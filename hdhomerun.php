<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

use Skywave\Hdhomerun\Device;
use Skywave\Hdhomerun\DiscoveredDevice;
use Skywave\Hdhomerun\Discovery;
use Skywave\Hdhomerun\Exception\DeviceErrorException;
use Skywave\Hdhomerun\Exception\HdhomerunException;
use Skywave\Hdhomerun\Packet;
use Skywave\Hdhomerun\Tuner;

require_once __DIR__ . '/vendor/autoload.php';

const USAGE = <<<'TXT'
Usage:
  hdhomerun discover [<ip>]
  hdhomerun <ip> info
  hdhomerun <ip> get <variable>
  hdhomerun <ip> set <variable> <value>
  hdhomerun <ip> status [<tuner>]
  hdhomerun <ip> tune <tuner> <channel>

Examples:
  hdhomerun discover
  hdhomerun 192.168.1.50 get /sys/model
  hdhomerun 192.168.1.50 get help
  hdhomerun 192.168.1.50 tune 0 auto:33

TXT;

$args = array_slice($argv, 1);

if ($args === []) {
    fwrite(STDERR, USAGE);
    exit(1);
}

try {
    exit($args[0] === 'discover' ? runDiscover($args[1] ?? null) : runDeviceCommand($args));
} catch (DeviceErrorException $e) {
    fwrite(STDERR, $e->getDeviceMessage() . "\n");
    exit(1);
} catch (HdhomerunException | InvalidArgumentException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

function runDiscover(?string $ip): int
{
    $discovery = new Discovery();
    $devices   = $ip === null
        ? $discovery->findDevices([Packet::DEVICE_TYPE_WILDCARD])
        : array_filter([$discovery->findDeviceAt($ip)]);

    if ($devices === []) {
        echo "no devices found\n";

        return 1;
    }

    foreach ($devices as $device) {
        printf("hdhomerun device %s found at %s\n", $device->getDeviceIdHex(), $device->getIp());
    }

    return 0;
}

/**
 * @param string[] $args
 */
function runDeviceCommand(array $args): int
{
    $host    = $args[0];
    $command = $args[1] ?? '';
    $params  = array_slice($args, 2);

    $expected = ['info' => 0, 'get' => 1, 'set' => 2, 'status' => -1, 'tune' => 2];

    if (!isset($expected[$command]) || ($expected[$command] >= 0 && count($params) !== $expected[$command])) {
        fwrite(STDERR, USAGE);

        return 1;
    }

    $device = Device::at($host);

    switch ($command) {
        case 'info':
            printInfo($device);
            break;

        case 'get':
            echo $device->getControl()->get($params[0]), "\n";
            break;

        case 'set':
            $device->getControl()->set($params[0], $params[1]);
            break;

        case 'status':
            $tuners = isset($params[0]) ? [$device->getTuner(parseTunerIndex($params[0]))] : $device->getTuners();

            foreach ($tuners as $tuner) {
                printTunerStatus($tuner);
            }
            break;

        case 'tune':
            $tuner = $device->getTuner(parseTunerIndex($params[0]));
            $tuner->setChannel($params[1]);
            $tuner->waitForLock();
            printTunerStatus($tuner);
            break;
    }

    return 0;
}

function parseTunerIndex(string $value): int
{
    if (!ctype_digit($value)) {
        throw new InvalidArgumentException("Invalid tuner index: $value");
    }

    return (int) $value;
}

function printInfo(Device $device): void
{
    $discovered = $device->getDiscovered();
    $hwModel    = $device->getHardwareModel();

    printRow('Host', $device->getHost());
    printRow('Device ID', $discovered === null ? '(not discovered)' : $discovered->getDeviceIdHex());
    printRow('Model', $device->getModel() . ($hwModel === null ? '' : " ($hwModel)"));
    printRow('Firmware', $device->getFirmwareVersion());
    printRow('Tuners', (string) $device->getTunerCount());
    printRow('Channel maps', implode(' ', $device->getChannelMaps()));

    if ($discovered instanceof DiscoveredDevice) {
        printRow('Legacy', $discovered->isLegacy() ? 'yes' : 'no');
        printRow('Base URL', $discovered->getBaseUrl() ?? '');
        printRow('Lineup URL', $discovered->getLineupUrl() ?? '');
    }
}

function printTunerStatus(Tuner $tuner): void
{
    $status = $tuner->getStatus();

    echo "Tuner {$tuner->getIndex()}\n";
    printRow('  Channel', $status->getChannel());
    printRow('  Lock', $status->getLock());
    printRow('  Signal strength', formatReading($status->getSignalStrength(), $status->getSignalStrengthDbm(), 'dBm', $status->getSignalStrengthColor()));
    printRow('  Signal quality', formatReading($status->getSignalToNoiseQuality(), $status->getSignalToNoiseDb(), 'dB', $status->getSignalToNoiseQualityColor()));
    printRow('  Symbol quality', formatReading($status->getSymbolErrorQuality(), null, '', $status->getSymbolErrorQualityColor()));
    printRow('  Network rate', sprintf('%.3f Mbps', $status->getBitsPerSecond() / 1000000));
    printRow('  Target', $tuner->getTarget());

    if (!$status->isLockSupported()) {
        return;
    }

    $virtual = $tuner->getVirtualStatus();

    if ($virtual !== null && $virtual->getVirtualChannel() !== '') {
        printRow('  Virtual channel', trim($virtual->getVirtualChannel() . ' ' . $virtual->getName()));
    }

    $streamInfo = $tuner->getStreamInfo();

    if ($streamInfo->getTransportStreamId() !== null) {
        printRow('  TSID', sprintf('%d (0x%04X)', $streamInfo->getTransportStreamId(), $streamInfo->getTransportStreamId()));
    }

    foreach ($streamInfo->getPrograms() as $index => $program) {
        printRow($index === 0 ? '  Programs' : '', $program->getLine());
    }
}

function formatReading(int $percent, ?float $decibels, string $unit, string $color): string
{
    $reading = "$percent%";

    if ($decibels !== null) {
        $reading .= sprintf(' (%.1f %s)', $decibels, $unit);
    }

    return "$reading [$color]";
}

function printRow(string $label, string $value): void
{
    printf("%-18s %s\n", $label === '' ? '' : "$label:", $value);
}
