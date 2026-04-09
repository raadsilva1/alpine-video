#!/usr/bin/env php
<?php

declare(strict_types=1);

final class AlpineVideoApp
{
    private const APP_NAME = 'alpine-video';
    private const APP_VERSION = '1.2.0';

    private bool $useColor;
    private bool $dryRun = false;
    private bool $assumeYes = false;

    public function __construct()
    {
        $this->useColor = $this->stdoutIsTty();
    }

    public function run(array $argv): int
    {
        array_shift($argv);

        [$globalOptions, $argv] = $this->extractGlobalOptions($argv);
        $this->dryRun = $globalOptions['dry-run'];
        $this->assumeYes = $globalOptions['yes'];

        if ($globalOptions['no-color']) {
            $this->useColor = false;
        }

        if ($globalOptions['version']) {
            $this->line(self::APP_NAME . ' ' . self::APP_VERSION);
            return 0;
        }

        if ($this->isHelpRequested($argv)) {
            $this->printHelp();
            return 0;
        }

        try {
            $this->guardEnvironment();

            if ($argv === []) {
                // Zero-interaction default: attempt a sane auto-fix.
                return $this->commandAutoFix();
            }

            $command = strtolower((string) array_shift($argv));

            return match ($command) {
                'status', 'list', 'ls' => $this->commandStatus(),
                'mirror', 'clone' => $this->commandMirror($argv),
                'only', 'single' => $this->commandOnly($argv),
                'off', 'disable' => $this->commandOff($argv),
                'auto', 'fix' => $this->commandAutoFix(),
                'interactive', 'menu', 'wizard' => $this->runInteractive(),
                default => $this->fail("Unknown command: {$command}\nUse --help to see valid commands."),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function extractGlobalOptions(array $args): array
    {
        $options = [
            'dry-run' => false,
            // Zero-interaction by default: don't prompt unless explicitly requested.
            'yes' => true,
            'no-color' => false,
            'version' => false,
        ];

        $remaining = [];
        foreach ($args as $arg) {
            switch ($arg) {
                case '--dry-run':
                case '-n':
                    $options['dry-run'] = true;
                    break;
                case '--yes':
                case '-y':
                    $options['yes'] = true;
                    break;
                case '--confirm':
                    $options['yes'] = false;
                    break;
                case '--no-color':
                    $options['no-color'] = true;
                    break;
                case '--version':
                case '-V':
                    $options['version'] = true;
                    break;
                default:
                    $remaining[] = $arg;
                    break;
            }
        }

        return [$options, $remaining];
    }

    private function isHelpRequested(array $argv): bool
    {
        foreach ($argv as $arg) {
            if (in_array($arg, ['-h', '--help', 'help'], true)) {
                return true;
            }
        }

        return false;
    }

    private function guardEnvironment(): void
    {
        if (!$this->commandExists('xrandr')) {
            throw new RuntimeException(
                'xrandr was not found. On Alpine, install it with: apk add xrandr'
            );
        }

        if (!$this->commandExists('php')) {
            throw new RuntimeException(
                'php was not found. On Alpine, install it with: apk add php84'
            );
        }

        $display = getenv('DISPLAY');
        if ($display === false || trim($display) === '') {
            throw new RuntimeException(
                'DISPLAY is not set. Run this inside your X11 session, not from a plain TTY.'
            );
        }

        $sessionType = strtolower((string) getenv('XDG_SESSION_TYPE'));
        if ($sessionType !== '' && $sessionType !== 'x11') {
            $this->warn(
                "Detected session type '{$sessionType}'. xrandr is for X11, so behavior may be limited."
            );
        }
    }

    private function commandStatus(): int
    {
        $outputs = $this->getOutputs();
        $this->banner();
        $this->printDashboard($outputs);
        return 0;
    }

    private function commandAutoFix(): int
    {
        $outputs = $this->getOutputs();
        $connected = $this->connectedOutputs($outputs);

        if (count($connected) < 2) {
            // Nothing to fix; show status so it's still useful.
            $this->banner();
            $this->printDashboard($outputs);
            $this->info('Nothing to change (fewer than two connected outputs).');
            return 0;
        }

        // Prefer an external display as the anchor (source) when present.
        $source = $this->pickAutoSource($outputs);
        $targets = array_values(array_filter(
            array_map(static fn(array $o): string => $o['name'], $connected),
            static function (string $name) use ($source): bool {
                return $name !== $source;
            }
        ));

        // Choose a shared mirror mode that will not exceed the smallest preferred/current among the group.
        $requestedMode = 'preferred';
        $mirrorOutputNames = array_merge([$source], $targets);
        $effectiveMode = $this->resolveMirrorMode($outputs, $mirrorOutputNames, $requestedMode, $source, $targets);
        [$fbW, $fbH] = $this->parseModeDimensions($effectiveMode);

        // Zero interaction: do not prompt.
        $prevAssumeYes = $this->assumeYes;
        $this->assumeYes = true;

        $external = $this->findFirstExternalOutputName($outputs);
        $primary = $external ?? $source;

        $command = ['xrandr', '--fb', "{$fbW}x{$fbH}"];

        // Configure source.
        $command[] = '--output';
        $command[] = $source;
        $this->appendOutputNormalization($command);
        $command[] = '--mode';
        $command[] = $effectiveMode;
        $command[] = '--pos';
        $command[] = '0x0';
        if ($primary === $source) {
            $command[] = '--primary';
        }

        // Configure targets as clones.
        foreach ($targets as $target) {
            $command[] = '--output';
            $command[] = $target;
            $this->appendOutputNormalization($command);
            $command[] = '--mode';
            $command[] = $effectiveMode;
            $command[] = '--pos';
            $command[] = '0x0';
            $command[] = '--same-as';
            $command[] = $source;
            if ($primary === $target) {
                $command[] = '--primary';
            }
        }

        // Turn off any other connected outputs outside the mirror group (defensive).
        $group = array_fill_keys($mirrorOutputNames, true);
        foreach ($connected as $o) {
            $name = $o['name'];
            if (isset($group[$name])) {
                continue;
            }
            $command[] = '--output';
            $command[] = $name;
            $command[] = '--off';
        }

        $summary = sprintf('Auto-fix: mirror %s -> %s at %s (no prompts)', $source, implode(', ', $targets), $effectiveMode);
        $this->info($summary);

        $resetResult = $this->runCommand($this->buildHardResetCommand($outputs), true);
        if ($resetResult['exit_code'] !== 0) {
            $this->warn('Pre-reset reported an error; continuing with a full reapply anyway.');
        }

        $result = $this->runCommand($command);
        $this->assumeYes = $prevAssumeYes;

        if ($result['exit_code'] !== 0) {
            $extra = trim($result['stderr']) !== '' ? "

{$result['stderr']}" : '';
            return $this->fail('Auto-fix failed to apply layout.' . $extra);
        }

        $this->runPostExecCommands('auto');
        $this->success("Auto-fix applied: mirrored at {$effectiveMode}.");
        return 0;
    }

    private function pickAutoSource(array $outputs): string
    {
        // Prefer the current primary if it's external; otherwise prefer any external output; else fall back to primary/first connected.
        $primary = $this->defaultSourceOutput($outputs);
        if ($primary !== null && !$this->isInternalOutputName($primary)) {
            return $primary;
        }

        $external = $this->findFirstExternalOutputName($outputs);
        if ($external !== null) {
            return $external;
        }

        return $primary ?? ($this->connectedOutputs($outputs)[0]['name']);
    }

    private function findFirstExternalOutputName(array $outputs): ?string
    {
        foreach ($outputs as $o) {
            if (!$o['connected']) {
                continue;
            }
            if ($this->isInternalOutputName($o['name'])) {
                continue;
            }
            return $o['name'];
        }
        return null;
    }

    private function isInternalOutputName(string $name): bool
    {
        $n = strtolower($name);
        // Common internal panel prefixes.
        return str_starts_with($n, 'edp') || str_starts_with($n, 'lvds') || str_starts_with($n, 'dsi');
    }

    private function commandMirror(array $args): int
    {
        $parsed = $this->parseMirrorArgs($args);
        $outputs = $this->getOutputs();
        $connected = $this->connectedOutputs($outputs);

        $source = $parsed['source'] ?? $this->defaultSourceOutput($outputs);
        if ($source === null) {
            return $this->fail('Could not determine a source output.');
        }

        $this->assertConnectedOutput($outputs, $source, 'Source output');

        $targets = $parsed['targets'];
        if ($targets !== []) {
            foreach ($targets as $target) {
                if ($target === $source) {
                    return $this->fail('Source and target cannot be the same output.');
                }
                $this->assertConnectedOutput($outputs, $target, 'Target output');
            }
        }

        if (count($connected) < 2) {
            $connectedNames = array_map(static fn(array $o): string => $o['name'], $connected);
            $detected = $connectedNames === [] ? 'none' : implode(', ', $connectedNames);
            return $this->fail('At least two connected outputs are required to mirror. Connected right now: ' . $detected);
        }

        if ($parsed['all'] || $targets === []) {
            $targets = array_values(array_filter(
                array_map(static fn(array $o): string => $o['name'], $connected),
                static fn(string $name): bool => $name !== $source
            ));
        }

        if ($targets === []) {
            return $this->fail('No target outputs were selected.');
        }

        foreach ($targets as $target) {
            if ($target === $source) {
                return $this->fail('Source and target cannot be the same output.');
            }
            $this->assertConnectedOutput($outputs, $target, 'Target output');
        }

        $requestedMode = $parsed['mode'];
        $rate = $parsed['rate'];
        $primary = $parsed['primary'] ?? $source;
        if ($primary === 'source') {
            $primary = $source;
        }
        $this->assertConnectedOutput($outputs, $primary, 'Primary output');

        $mirrorOutputs = [$source];
        foreach ($targets as $target) {
            $mirrorOutputs[] = $target;
        }

        $effectiveMode = $this->resolveMirrorMode($outputs, $mirrorOutputs, $requestedMode, $source, $targets);
        [$fbW, $fbH] = $this->parseModeDimensions($effectiveMode);

        // Force a deterministic, non-extended layout.
        $command = ['xrandr', '--fb', "{$fbW}x{$fbH}"];

        $command[] = '--output';
        $command[] = $source;
        $this->appendOutputNormalization($command);
        $command[] = '--mode';
        $command[] = $effectiveMode;
        $command[] = '--pos';
        $command[] = '0x0';
        if ($rate !== null) {
            $command[] = '--rate';
            $command[] = $rate;
        }
        if ($primary === $source) {
            $command[] = '--primary';
        }

        foreach ($targets as $target) {
            $command[] = '--output';
            $command[] = $target;
            $this->appendOutputNormalization($command);
            $command[] = '--mode';
            $command[] = $effectiveMode;
            $command[] = '--pos';
            $command[] = '0x0';

            if ($rate !== null) {
                $command[] = '--rate';
                $command[] = $rate;
            }

            $command[] = '--same-as';
            $command[] = $source;

            if ($primary === $target) {
                $command[] = '--primary';
            }
        }

        if ($parsed['off-others']) {
            foreach ($connected as $output) {
                $name = $output['name'];
                if ($name === $source || in_array($name, $targets, true)) {
                    continue;
                }
                $command[] = '--output';
                $command[] = $name;
                $command[] = '--off';
            }
        }

        $summary = sprintf(
            'Mirror %s -> %s at %s%s',
            $source,
            implode(', ', $targets),
            $effectiveMode,
            $parsed['off-others'] ? ' (turn off other outputs)' : ''
        );

        if (!$this->confirmAction($summary)) {
            $this->info('Cancelled.');
            return 0;
        }

        $resetResult = $this->runCommand($this->buildHardResetCommand($outputs), true);
        if ($resetResult['exit_code'] !== 0) {
            $this->warn('Pre-reset reported an error; continuing with the requested layout.');
        }

        $result = $this->runCommand($command);
        if ($result['exit_code'] !== 0) {
            $extra = trim($result['stderr']) !== '' ? "\n\n{$result['stderr']}" : '';
            return $this->fail(
                'xrandr failed to apply the mirror layout.' .
                $extra .
                "\nTip: try --mode current or an explicit common mode such as 1440x900."
            );
        }

        $this->runPostExecCommands('mirror');
        $this->success("Mirror layout applied successfully at {$effectiveMode}.");
        return 0;
    }

    private function commandOnly(array $args): int
    {
        $outputName = $args[0] ?? null;
        if ($outputName === null || str_starts_with($outputName, '-')) {
            return $this->fail('Usage: only OUTPUT');
        }

        $outputs = $this->getOutputs();
        $this->assertConnectedOutput($outputs, $outputName, 'Output');

        $selectedOutput = $this->findOutput($outputs, $outputName);
        if ($selectedOutput === null) {
            return $this->fail("Output '{$outputName}' was not found.");
        }

        $mode = $selectedOutput['preferred_mode'] ?? $selectedOutput['current_mode'] ?? null;
        if ($mode === null) {
            return $this->fail("Could not determine a usable mode for {$outputName}.");
        }

        [$fbW, $fbH] = $this->parseModeDimensions($mode);

        $command = ['xrandr', '--fb', "{$fbW}x{$fbH}"];

        foreach ($this->connectedOutputs($outputs) as $output) {
            $command[] = '--output';
            $command[] = $output['name'];

            if ($output['name'] === $outputName) {
                $this->appendOutputNormalization($command);
                $command[] = '--mode';
                $command[] = $mode;
                $command[] = '--pos';
                $command[] = '0x0';
                $command[] = '--primary';
            } else {
                $command[] = '--off';
            }
        }

        if (!$this->confirmAction("Use only {$outputName} at {$mode} and turn off the others")) {
            $this->info('Cancelled.');
            return 0;
        }

        $resetResult = $this->runCommand($this->buildHardResetCommand($outputs), true);
        if ($resetResult['exit_code'] !== 0) {
            $this->warn('Pre-reset reported an error; continuing with the requested single-output layout.');
        }

        $result = $this->runCommand($command);
        if ($result['exit_code'] !== 0) {
            return $this->fail('xrandr failed to switch to a single-output layout.

' . trim($result['stderr']));
        }

        $this->runPostExecCommands('only');
        $this->success("Now using only {$outputName} at {$mode}.");
        return 0;
    }


    private function appendOutputNormalization(array &$command): void
    {
        // Clear state that can preserve an "extended-like" desktop even after mirroring.
        $command[] = '--transform';
        $command[] = 'none';
        $command[] = '--scale';
        $command[] = '1x1';
        $command[] = '--rotate';
        $command[] = 'normal';
        $command[] = '--reflect';
        $command[] = 'normal';
        $command[] = '--panning';
        $command[] = '0x0';
    }

    private function buildHardResetCommand(array $outputs): array
    {
        $command = ['xrandr'];
        foreach ($this->connectedOutputs($outputs) as $output) {
            $command[] = '--output';
            $command[] = $output['name'];
            $command[] = '--off';
        }
        return $command;
    }

    private function commandOff(array $args): int
    {
        $targets = array_values(array_filter($args, static fn(string $arg): bool => !str_starts_with($arg, '-')));
        if ($targets === []) {
            return $this->fail('Usage: off OUTPUT [OUTPUT ...]');
        }

        $outputs = $this->getOutputs();
        foreach ($targets as $target) {
            $this->assertConnectedOutput($outputs, $target, 'Output');
        }

        $connected = $this->connectedOutputs($outputs);
        if (count($connected) <= count($targets)) {
            return $this->fail('Refusing to turn off every connected output.');
        }

        $command = ['xrandr'];
        foreach ($targets as $target) {
            $command[] = '--output';
            $command[] = $target;
            $command[] = '--off';
        }

        if (!$this->confirmAction('Turn off: ' . implode(', ', $targets))) {
            $this->info('Cancelled.');
            return 0;
        }

        $result = $this->runCommand($command);
        if ($result['exit_code'] !== 0) {
            return $this->fail('xrandr failed to turn off the requested outputs.\n\n' . trim($result['stderr']));
        }

        $this->runPostExecCommands('off');
        $this->success('Requested outputs were turned off.');
        return 0;
    }

    private function runPostExecCommands(string $action): void
    {
        if ($this->dryRun) {
            return;
        }

        $commands = $this->loadPostExecCommands();
        if ($commands === []) {
            return;
        }

        $configPath = $this->getUserConfigPath();
        if ($configPath !== null) {
            $this->info("Running post-exec commands from {$configPath}.");
        }

        foreach ($commands as $index => $commandString) {
            $hookNumber = $index + 1;
            $this->info("Post-exec #{$hookNumber}: {$commandString}");
            $result = $this->runCommand(['sh', '-lc', $commandString], true);
            if ($result['exit_code'] !== 0) {
                $stderr = trim($result['stderr']);
                $detail = $stderr !== '' ? " {$stderr}" : '';
                $this->warn("Post-exec #{$hookNumber} failed after a successful xrandr operation.{$detail}");
            }
        }
    }

    private function loadPostExecCommands(): array
    {
        $configPath = $this->getUserConfigPath();
        if ($configPath === null || !is_file($configPath)) {
            return [];
        }

        if (!is_readable($configPath)) {
            $this->warn("Cannot read {$configPath}; skipping post-exec commands.");
            return [];
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            $this->warn("Could not read {$configPath}; skipping post-exec commands.");
            return [];
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            return [];
        }

        $data = null;

        if (str_starts_with($trimmed, '<?php')) {
            $data = (static function (string $path) {
                return include $path;
            })($configPath);
        } else {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            } else {
                $lines = preg_split('/\R/', $trimmed) ?: [];
                $data = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $data[] = $line;
                }
            }
        }

        return $this->normalizePostExecCommands($data, $configPath);
    }

    private function normalizePostExecCommands(mixed $data, string $configPath): array
    {
        if (!is_array($data)) {
            $this->warn("{$configPath} does not define a valid command array; skipping post-exec commands.");
            return [];
        }

        $candidate = null;

        if (array_is_list($data)) {
            $candidate = $data;
        } else {
            foreach (['post_exec_commands', 'post_exec', 'commands'] as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    $candidate = $data[$key];
                    break;
                }
            }
        }

        if ($candidate === null) {
            $this->warn("{$configPath} does not contain a supported post-exec command array; skipping post-exec commands.");
            return [];
        }

        $commands = [];
        foreach ($candidate as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $commands[] = $item;
        }

        return array_values(array_unique($commands));
    }

    private function getUserConfigPath(): ?string
    {
        $home = getenv('HOME');
        if (!is_string($home) || trim($home) === '') {
            return null;
        }

        return rtrim($home, '/') . '/.alpine-video';
    }

    private function parseMirrorArgs(array $args): array
    {
        $parsed = [
            'source' => null,
            'targets' => [],
            'all' => false,
            'mode' => 'preferred',
            'rate' => null,
            'primary' => null,
            // Default: avoid accidental extended desktops.
            'off-others' => true,
        ];

        for ($i = 0, $max = count($args); $i < $max; $i++) {
            $arg = $args[$i];

            if ($arg === '--all') {
                $parsed['all'] = true;
                continue;
            }
            if ($arg === '--off-others') {
                $parsed['off-others'] = true;
                continue;
            }
            if ($arg === '--keep-others') {
                $parsed['off-others'] = false;
                continue;
            }

            if (str_starts_with($arg, '--source=')) {
                $parsed['source'] = substr($arg, 9);
                continue;
            }
            if ($arg === '--source') {
                $parsed['source'] = $args[++$i] ?? null;
                continue;
            }

            if (str_starts_with($arg, '--targets=')) {
                $parsed['targets'] = $this->csvToList(substr($arg, 10));
                continue;
            }
            if ($arg === '--targets') {
                $parsed['targets'] = $this->csvToList($args[++$i] ?? '');
                continue;
            }

            if (str_starts_with($arg, '--mode=')) {
                $parsed['mode'] = strtolower(substr($arg, 7));
                continue;
            }
            if ($arg === '--mode') {
                $parsed['mode'] = strtolower((string) ($args[++$i] ?? ''));
                continue;
            }

            if (str_starts_with($arg, '--rate=')) {
                $parsed['rate'] = substr($arg, 7);
                continue;
            }
            if ($arg === '--rate') {
                $parsed['rate'] = $args[++$i] ?? null;
                continue;
            }

            if (str_starts_with($arg, '--primary=')) {
                $parsed['primary'] = substr($arg, 10);
                continue;
            }
            if ($arg === '--primary') {
                $parsed['primary'] = $args[++$i] ?? null;
                continue;
            }

            if (!str_starts_with($arg, '-')) {
                if ($parsed['source'] === null) {
                    $parsed['source'] = $arg;
                } else {
                    $parsed['targets'][] = $arg;
                }
                continue;
            }

            throw new RuntimeException("Unknown mirror option: {$arg}");
        }

        $validModes = ['preferred', 'auto', 'current'];
        if ($parsed['mode'] === '' || (!in_array($parsed['mode'], $validModes, true) && !preg_match('/^\d+x\d+$/', $parsed['mode']))) {
            throw new RuntimeException('Invalid --mode value. Use preferred, auto, current, or an explicit mode like 1920x1080.');
        }

        if ($parsed['rate'] !== null && !preg_match('/^\d+(?:\.\d+)?$/', $parsed['rate'])) {
            throw new RuntimeException('Invalid --rate value. Example: 60 or 59.94');
        }

        return $parsed;
    }

    private function runInteractive(): int
    {
        while (true) {
            $outputs = $this->getOutputs();
            $this->banner();
            $this->printDashboard($outputs);
            $this->printInteractiveMenu();

            $choice = strtolower(trim($this->prompt('Choose an action', '1')));
            $this->line('');

            switch ($choice) {
                case '1':
                case 'mirror':
                case 'm':
                    $result = $this->interactiveMirror($outputs);
                    if ($result !== 0) {
                        return $result;
                    }
                    break;
                case '2':
                case 'only':
                case 'single':
                    $result = $this->interactiveOnly($outputs);
                    if ($result !== 0) {
                        return $result;
                    }
                    break;
                case '3':
                case 'off':
                    $result = $this->interactiveOff($outputs);
                    if ($result !== 0) {
                        return $result;
                    }
                    break;
                case '4':
                case 'status':
                case 'refresh':
                case 'r':
                    break;
                case '5':
                case 'help':
                case '?':
                    $this->printHelp();
                    $this->pause();
                    break;
                case '0':
                case 'q':
                case 'quit':
                case 'exit':
                    $this->info('Bye.');
                    return 0;
                default:
                    $this->warn('Invalid option. Choose 0 to 5.');
                    break;
            }

            $this->line('');
        }
    }

    private function interactiveMirror(array $outputs): int
    {
        $connected = $this->connectedOutputs($outputs);
        if (count($connected) < 2) {
            return $this->fail('At least two connected outputs are required to mirror.');
        }

        $defaultSource = $this->defaultSourceOutput($outputs);
        $source = $this->pickOutput(
            $connected,
            'Choose the source output (the picture to be copied)',
            $defaultSource
        );
        if ($source === null) {
            $this->info('Mirror wizard cancelled.');
            return 0;
        }

        $availableTargets = array_values(array_filter(
            $connected,
            static fn(array $o): bool => $o['name'] !== $source
        ));

        $targetChoices = array_map(static fn(array $o): string => $o['name'], $availableTargets);
        $targets = $this->pickMultipleOutputs(
            $targetChoices,
            'Choose target outputs (comma-separated indexes, empty = all targets)'
        );
        if ($targets === []) {
            $targets = $targetChoices;
        }

        $mirrorOutputNames = array_merge([$source], $targets);
        $commonModes = $this->commonModesForNames($outputs, $mirrorOutputNames);
        if ($commonModes === []) {
            return $this->fail('The chosen outputs do not share any common mirror resolution.');
        }

        $recommendedMode = $this->resolveMirrorMode($outputs, $mirrorOutputNames, 'preferred', $source, $targets);
        $this->showModeSuggestions($commonModes, $recommendedMode);

        $rawMode = strtolower(trim($this->prompt('Mirror resolution [Enter = recommended]', $recommendedMode)));
        $mode = $rawMode === '' ? $recommendedMode : $rawMode;

        $offOthers = $this->askYesNo('Turn off connected outputs outside this mirror group?', true);

        return $this->commandMirror([
            '--source', $source,
            '--targets', implode(',', $targets),
            '--mode', $mode,
            ...($offOthers ? ['--off-others'] : ['--keep-others']),
        ]);
    }

    private function interactiveOnly(array $outputs): int
    {
        $connected = $this->connectedOutputs($outputs);
        $default = $this->defaultSourceOutput($outputs);
        $output = $this->pickOutput($connected, 'Choose the output to keep enabled', $default);
        if ($output === null) {
            $this->info('Single-output action cancelled.');
            return 0;
        }

        return $this->commandOnly([$output]);
    }

    private function interactiveOff(array $outputs): int
    {
        $connected = $this->connectedOutputs($outputs);
        if (count($connected) < 2) {
            return $this->fail('You need at least two connected outputs before disabling one.');
        }

        $names = array_map(static fn(array $o): string => $o['name'], $connected);
        $targets = $this->pickMultipleOutputs($names, 'Choose outputs to turn off (comma-separated indexes)');
        if ($targets === []) {
            $this->warn('No outputs selected.');
            return 0;
        }

        return $this->commandOff($targets);
    }

    private function getOutputs(): array
    {
        $result = $this->runCommand(['xrandr', '--query'], true);
        if ($result['exit_code'] !== 0) {
            $stderr = trim($result['stderr']);
            throw new RuntimeException(
                'Failed to query xrandr.' . ($stderr !== '' ? "\n\n{$stderr}" : '')
            );
        }

        $lines = preg_split('/\R/', trim($result['stdout'])) ?: [];
        $outputs = [];
        $currentOutputIndex = null;

        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z0-9_.:-]+)\s+(connected|disconnected)(?:\s+primary)?(?:\s+([0-9]+x[0-9]+)\+[0-9]+\+[0-9]+)?/', $line, $m)) {
                $name = $m[1];
                $connected = $m[2] === 'connected';
                $primary = str_contains($line, ' primary ');
                $currentMode = $m[3] ?? null;

                $outputs[] = [
                    'name' => $name,
                    'connected' => $connected,
                    'primary' => $primary,
                    'current_mode' => $currentMode,
                    'preferred_mode' => null,
                    'modes' => [],
                    'raw' => $line,
                ];
                $currentOutputIndex = array_key_last($outputs);
                continue;
            }

            if ($currentOutputIndex !== null && preg_match('/^\s+([0-9]+x[0-9]+)\s+(.+)$/', $line, $m)) {
                $mode = $m[1];
                $flags = $m[2];
                $outputs[$currentOutputIndex]['modes'][] = $mode;
                if (str_contains($flags, '+') && $outputs[$currentOutputIndex]['preferred_mode'] === null) {
                    $outputs[$currentOutputIndex]['preferred_mode'] = $mode;
                }
                if (str_contains($flags, '*')) {
                    $outputs[$currentOutputIndex]['current_mode'] = $mode;
                }
            }
        }

        if ($outputs === []) {
            throw new RuntimeException('No xrandr outputs were detected.');
        }

        return $outputs;
    }

    private function printDashboard(array $outputs): void
    {
        $connected = $this->connectedOutputs($outputs);
        $connectedNames = array_map(static fn(array $o): string => $o['name'], $connected);
        $primary = $this->defaultSourceOutput($outputs) ?? 'none';

        $this->line($this->style('Overview', 'bold'));
        $this->line('  Connected outputs : ' . count($connected) . ($connectedNames !== [] ? ' (' . implode(', ', $connectedNames) . ')' : ''));
        $this->line('  Primary output    : ' . $primary);
        $this->line('  Session           : ' . (getenv('XDG_SESSION_TYPE') ?: 'unknown') . ' / DISPLAY=' . (getenv('DISPLAY') ?: 'unset'));
        $this->line('');
        $this->printOutputs($outputs);
    }

    private function printOutputs(array $outputs): void
    {
        $this->line($this->style('Detected outputs', 'bold'));
        foreach ($outputs as $index => $output) {
            $status = $output['connected'] ? $this->style('connected', 'green') : $this->style('disconnected', 'red');
            $flags = [];
            if ($output['primary']) {
                $flags[] = 'primary';
            }
            if ($output['current_mode'] !== null) {
                $flags[] = 'current=' . $output['current_mode'];
            }
            if ($output['preferred_mode'] !== null) {
                $flags[] = 'preferred=' . $output['preferred_mode'];
            }

            $suffix = $flags !== [] ? ' [' . implode(', ', $flags) . ']' : '';
            $this->line(sprintf('  %d) %-14s %s%s', $index + 1, $output['name'], $status, $suffix));
        }
    }

    private function printInteractiveMenu(): void
    {
        $this->line('');
        $this->line($this->style('Actions', 'bold'));
        $this->line('  1) Mirror outputs (guided wizard)');
        $this->line('  2) Use one output only');
        $this->line('  3) Turn off selected outputs');
        $this->line('  4) Refresh status');
        $this->line('  5) Help / examples');
        $this->line('  0) Exit');
        $this->line('');
        $this->line("Tip: run without arguments for zero-interaction auto-fix. Use 'menu' for the wizard.");
    }

    private function connectedOutputs(array $outputs): array
    {
        return array_values(array_filter($outputs, static fn(array $o): bool => $o['connected']));
    }

    private function defaultSourceOutput(array $outputs): ?string
    {
        foreach ($outputs as $output) {
            if ($output['connected'] && $output['primary']) {
                return $output['name'];
            }
        }

        foreach ($outputs as $output) {
            if ($output['connected']) {
                return $output['name'];
            }
        }

        return null;
    }

    private function findOutput(array $outputs, string $name): ?array
    {
        foreach ($outputs as $output) {
            if ($output['name'] === $name) {
                return $output;
            }
        }
        return null;
    }

    private function resolveMirrorMode(array $outputs, array $mirrorOutputNames, string $requestedMode, string $source, array $targets): string
    {
        $mirrorOutputs = [];
        foreach ($mirrorOutputNames as $name) {
            $output = $this->findOutput($outputs, $name);
            if ($output === null || !$output['connected']) {
                throw new RuntimeException("Output '{$name}' is not available for mirroring.");
            }
            $mirrorOutputs[] = $output;
        }

        $commonModes = $this->commonModes($mirrorOutputs);
        if ($commonModes === []) {
            $names = implode(', ', $mirrorOutputNames);
            throw new RuntimeException("These outputs do not share any common mode: {$names}");
        }

        if ($requestedMode === 'current') {
            $sourceOutput = $this->findOutput($outputs, $source);
            $currentMode = $sourceOutput['current_mode'] ?? null;
            if ($currentMode === null) {
                throw new RuntimeException("The source output {$source} has no current mode to reuse.");
            }
            if (!in_array($currentMode, $commonModes, true)) {
                throw new RuntimeException(
                    "The source current mode {$currentMode} is not supported by all mirror outputs. Common modes: " . implode(', ', $commonModes)
                );
            }
            return $currentMode;
        }

        if ($requestedMode !== 'preferred' && $requestedMode !== 'auto') {
            if (!in_array($requestedMode, $commonModes, true)) {
                throw new RuntimeException(
                    "Requested mode {$requestedMode} is not supported by all mirror outputs. Common modes: " . implode(', ', $commonModes)
                );
            }
            return $requestedMode;
        }

        $budgetArea = null;
        foreach ($mirrorOutputs as $output) {
            $candidate = $output['preferred_mode'] ?? $output['current_mode'];
            if ($candidate === null) {
                continue;
            }
            [$w, $h] = $this->parseModeDimensions($candidate);
            $area = $w * $h;
            if ($budgetArea === null || $area < $budgetArea) {
                $budgetArea = $area;
            }
        }

        if ($budgetArea !== null) {
            $budgeted = array_values(array_filter($commonModes, function (string $mode) use ($budgetArea): bool {
                [$w, $h] = $this->parseModeDimensions($mode);
                return ($w * $h) <= $budgetArea;
            }));
            if ($budgeted !== []) {
                $commonModes = $budgeted;
            }
        }

        usort($commonModes, function (string $a, string $b): int {
            [$aw, $ah] = $this->parseModeDimensions($a);
            [$bw, $bh] = $this->parseModeDimensions($b);
            $areaCmp = ($bw * $bh) <=> ($aw * $ah);
            if ($areaCmp !== 0) {
                return $areaCmp;
            }
            $widthCmp = $bw <=> $aw;
            if ($widthCmp !== 0) {
                return $widthCmp;
            }
            return $bh <=> $ah;
        });

        $targetPreferred = null;
        if (count($targets) === 1) {
            $targetOutput = $this->findOutput($outputs, $targets[0]);
            $targetPreferred = $targetOutput['preferred_mode'] ?? null;
            if ($targetPreferred !== null && in_array($targetPreferred, $commonModes, true)) {
                return $targetPreferred;
            }
        }

        return $commonModes[0];
    }

    private function commonModes(array $outputs): array
    {
        $common = null;
        foreach ($outputs as $output) {
            $modes = array_values(array_unique($output['modes']));
            if ($common === null) {
                $common = $modes;
                continue;
            }
            $common = array_values(array_intersect($common, $modes));
        }

        return $common ?? [];
    }

    private function commonModesForNames(array $outputs, array $names): array
    {
        $mirrorOutputs = [];
        foreach ($names as $name) {
            $output = $this->findOutput($outputs, $name);
            if ($output === null || !$output['connected']) {
                throw new RuntimeException("Output '{$name}' is not available.");
            }
            $mirrorOutputs[] = $output;
        }

        $common = $this->commonModes($mirrorOutputs);
        usort($common, function (string $a, string $b): int {
            [$aw, $ah] = $this->parseModeDimensions($a);
            [$bw, $bh] = $this->parseModeDimensions($b);
            return ($bw * $bh) <=> ($aw * $ah);
        });

        return $common;
    }

    private function showModeSuggestions(array $commonModes, string $recommendedMode): void
    {
        $topModes = array_slice($commonModes, 0, 8);
        $this->line('');
        $this->line($this->style('Mirror resolution suggestions', 'bold'));
        $this->line('  Recommended : ' . $recommendedMode);
        $this->line('  Common modes: ' . implode(', ', $topModes));
        if (count($commonModes) > count($topModes)) {
            $this->line('  More modes   : available, but hidden to keep the menu clean');
        }
        $this->line('');
    }

    private function parseModeDimensions(string $mode): array
    {
        if (!preg_match('/^(\d+)x(\d+)$/', $mode, $m)) {
            throw new RuntimeException("Invalid mode string: {$mode}");
        }
        return [(int) $m[1], (int) $m[2]];
    }

    private function assertConnectedOutput(array $outputs, string $name, string $label): void
    {
        $output = $this->findOutput($outputs, $name);
        if ($output === null) {
            throw new RuntimeException("{$label} '{$name}' was not found.");
        }
        if (!$output['connected']) {
            throw new RuntimeException("{$label} '{$name}' is currently disconnected.");
        }
    }

    private function pickOutput(array $outputs, string $prompt, ?string $default = null): ?string
    {
        foreach ($outputs as $index => $output) {
            $extra = $output['name'] === $default ? ' (default)' : '';
            $this->line(sprintf('  %d) %s%s', $index + 1, $output['name'], $extra));
        }
        $this->line('  0) Cancel');

        $defaultIndex = $default !== null ? (string) $this->findOutputIndex($outputs, $default) : null;
        $raw = trim($this->prompt($prompt, $defaultIndex));
        if ($raw === '') {
            return $default;
        }
        if (in_array(strtolower($raw), ['0', 'q', 'quit', 'exit', 'cancel'], true)) {
            return null;
        }

        if ($this->isUnsignedInteger($raw)) {
            $idx = (int) $raw - 1;
            if (isset($outputs[$idx])) {
                return $outputs[$idx]['name'];
            }
        }

        foreach ($outputs as $output) {
            if ($output['name'] === $raw) {
                return $raw;
            }
        }

        throw new RuntimeException('Invalid output selection.');
    }

    private function pickMultipleOutputs(array $names, string $prompt): array
    {
        foreach ($names as $index => $name) {
            $this->line(sprintf('  %d) %s', $index + 1, $name));
        }
        $this->line('  0) Cancel');

        $raw = trim($this->prompt($prompt, ''));
        if ($raw === '') {
            return [];
        }
        if (in_array(strtolower($raw), ['0', 'q', 'quit', 'exit', 'cancel'], true)) {
            return [];
        }

        $pieces = preg_split('/\s*,\s*/', $raw) ?: [];
        $selected = [];
        foreach ($pieces as $piece) {
            if ($piece === '') {
                continue;
            }
            if ($this->isUnsignedInteger($piece)) {
                $idx = (int) $piece - 1;
                if (!isset($names[$idx])) {
                    throw new RuntimeException("Invalid selection index: {$piece}");
                }
                $selected[] = $names[$idx];
                continue;
            }

            if (!in_array($piece, $names, true)) {
                throw new RuntimeException("Unknown output: {$piece}");
            }
            $selected[] = $piece;
        }

        return array_values(array_unique($selected));
    }

    private function findOutputIndex(array $outputs, string $name): ?int
    {
        foreach ($outputs as $index => $output) {
            if ($output['name'] === $name) {
                return $index + 1;
            }
        }
        return null;
    }

    private function isUnsignedInteger(string $value): bool
    {
        return $value !== '' && preg_match('/^[0-9]+$/', $value) === 1;
    }

    private function askYesNo(string $question, bool $default): bool
    {
        $defaultText = $default ? 'Y/n' : 'y/N';
        $answer = strtolower(trim($this->prompt("{$question} [{$defaultText}]", '')));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes'], true);
    }

    private function confirmAction(string $summary): bool
    {
        if ($this->dryRun) {
            $this->info('Dry run enabled. No changes will be applied.');
            return true;
        }

        if ($this->assumeYes) {
            return true;
        }

        return $this->askYesNo($summary . '. Proceed?', true);
    }

    private function runCommand(array $command, bool $allowFailure = false): array
    {
        $rendered = $this->shellJoin($command);
        if ($this->dryRun) {
            $this->line($this->style('[dry-run]', 'yellow') . ' ' . $rendered);
            return [
                'exit_code' => 0,
                'stdout' => '',
                'stderr' => '',
                'command' => $rendered,
            ];
        }

        $this->line($this->style('> ', 'cyan') . $rendered);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($rendered, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the command: ' . $rendered);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0 && !$allowFailure) {
            return [
                'exit_code' => $exitCode,
                'stdout' => (string) $stdout,
                'stderr' => (string) $stderr,
                'command' => $rendered,
            ];
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'command' => $rendered,
        ];
    }

    private function shellJoin(array $command): string
    {
        return implode(' ', array_map(static function (string $piece): string {
            return escapeshellarg($piece);
        }, $command));
    }

    private function csvToList(string $value): array
    {
        $parts = preg_split('/\s*,\s*/', trim($value)) ?: [];
        return array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
    }

    private function commandExists(string $command): bool
    {
        $result = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
        return is_string($result) && trim($result) !== '';
    }

    private function stdoutIsTty(): bool
    {
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }
        return false;
    }

    private function banner(): void
    {
        $this->line($this->style(self::APP_NAME, 'bold') . ' ' . self::APP_VERSION . ' - friendly xrandr helper for Alpine/X11');
        if ($this->dryRun) {
            $this->warn('Dry-run mode is active. Commands will be shown but not executed.');
        }
    }

    private function printHelp(): void
    {
        $help = <<<TXT
alpine-video - friendly xrandr wrapper for Alpine Linux

Usage:
  php alpine-video.php                 Auto-fix (zero interaction)
  php alpine-video.php menu            Open the interactive menu
  php alpine-video.php status          Show outputs and modes
  php alpine-video.php mirror          Start mirror logic using defaults
  php alpine-video.php only HDMI-1     Keep only HDMI-1 enabled
  php alpine-video.php off HDMI-1      Turn off HDMI-1

Mirror examples:
  php alpine-video.php mirror --source eDP-1 --targets HDMI-1
  php alpine-video.php mirror eDP-1 HDMI-1 DP-1
  php alpine-video.php mirror --source HDMI-1 --all --off-others
  php alpine-video.php mirror --source eDP-1 --targets HDMI-1 --mode 1440x900
  php alpine-video.php mirror --source eDP-1 --targets HDMI-1 --mode current

Global options:
  --dry-run, -n     Show commands without applying them
  --yes, -y         Skip confirmation prompts (default)
  --confirm         Ask before applying changes
  --no-color        Disable ANSI colors
  --version, -V     Show the current version
  --help, -h        Show this help

Mirror options:
  --source OUTPUT
  --targets A,B,C
  --all
  --mode preferred|auto|current|WIDTHxHEIGHT
  --rate 60
  --primary OUTPUT|source
  --off-others      Turn off other connected outputs (default)
  --keep-others     Keep other connected outputs enabled

Notes:
  - This tool is meant for X11 sessions because it wraps xrandr.
  - On Alpine, install dependencies with: apk add php84 xrandr
  - The interactive wizard suggests a shared mirror resolution automatically.
  - If ~/.alpine-video exists, post-exec commands from that file run after each successful xrandr change.
  - Supported ~/.alpine-video formats:
      * JSON array: ["setxkbmap br abnt2", "herbstclient reload"]
      * JSON object: {"post_exec_commands":["setxkbmap br abnt2"]}
      * PHP file: <?php return ['post_exec_commands' => ['setxkbmap br abnt2']];
      * Plain text: one shell command per line, # for comments
TXT;

        $this->line($help);
    }

    private function style(string $text, string $kind): string
    {
        if (!$this->useColor) {
            return $text;
        }

        $map = [
            'red' => '0;31',
            'green' => '0;32',
            'yellow' => '0;33',
            'blue' => '0;34',
            'cyan' => '0;36',
            'bold' => '1',
        ];

        $code = $map[$kind] ?? null;
        if ($code === null) {
            return $text;
        }

        return "\033[{$code}m{$text}\033[0m";
    }

    private function prompt(string $label, ?string $default = null): string
    {
        $suffix = $default !== null && $default !== '' ? " [{$default}]" : '';
        $this->raw("{$label}{$suffix}: ");
        $line = fgets(STDIN);
        return $line === false ? '' : rtrim($line, "\r\n");
    }

    private function pause(): void
    {
        $this->prompt('Press Enter to continue', '');
    }

    private function line(string $text): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    private function raw(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    private function info(string $text): void
    {
        $this->line($this->style('[info]', 'blue') . ' ' . $text);
    }

    private function warn(string $text): void
    {
        $this->line($this->style('[warn]', 'yellow') . ' ' . $text);
    }

    private function error(string $text): void
    {
        fwrite(STDERR, $this->style('[error]', 'red') . ' ' . $text . PHP_EOL);
    }

    private function success(string $text): void
    {
        $this->line($this->style('[ok]', 'green') . ' ' . $text);
    }

    private function fail(string $text): int
    {
        $this->error($text);
        return 1;
    }
}

$app = new AlpineVideoApp();
exit($app->run($argv));
