<?php

namespace RrdCached;

use Socket\Raw\Socket;
use Socket\Raw\Factory;

class RrdCachedClient
{
    /** @var bool */
    protected $autoParse = true;

    /** @var bool */
    protected $batchMode = false;

    /** @var array */
    protected $batchCommands = [];

    /** @var string */
    protected $socketPath;

    /** @var Socket */
    protected $socket;

    /** @var array */
    public $defaultCreateParams = [];

    /** @var int */
    public $defaultCreateStep = 300;

    function __construct($socketPath = 'unix:///var/run/rrdcached.sock')
    {
        $this->socketPath = $socketPath;
    }

    function connect()
    {
        $factory = new Factory();
        $this->socket = $factory->createClient($this->socketPath);

    }

    function disconnect()
    {
        $this->socket->close();
    }

    function write($command)
    {
        $this->socket->write($command);
    }

    protected function parseLn(string $line): int
    {
        $parts = explode(' ', $line);
        return (int)$parts[0];
    }

    protected function readAndParse(): string
    {
        $readBuffLen = 2048;
        $serverMessage = $this->socket->read($readBuffLen, PHP_NORMAL_READ);
        $returnCode = $this->parseLn($serverMessage);

        $result = '';

        for ($i = 1; $i <= $returnCode; $i++) {
            $result .= $this->socket->read($readBuffLen, PHP_NORMAL_READ);
        }

        return '' !== $result ? $result : $serverMessage;
    }

    function help($command): string
    {
        $this->write('HELP ' . $command . PHP_EOL);
        return $this->readAndParse();
    }

    function stats()
    {
        $this->write('STATS' . PHP_EOL);
        return $this->readAndParse();
    }

    function quit()
    {
        $this->write('QUIT' . PHP_EOL);
    }

    function update($fileName, array $options)
    {
        if ($this->batchMode) {
            $this->batchCommands[] = [
                'command' => 'update',
                'fileName' => $fileName,
                'options' => $options
            ];
        } else {
            $this->write('UPDATE ' . $fileName . ' ' . implode(':', $options) . PHP_EOL);

            if ($this->autoParse) {
                return $this->readAndParse();
            }
        }
    }

    protected function updateErrorHandler(string $fileName, array $options)
    {
        $this->autoParse = false;

        $this->create($fileName, $this->defaultCreateParams);
        $this->update($fileName, $options);
    }

    function create(string $fileName, array $options)
    {
        if (0 < count($options)) {
            $workOptions = $options;
        } elseif (0 < count($this->defaultCreateParams)) {
            $workOptions = $this->defaultCreateParams;
        } else {
            throw new RrdCachedException('Missing options for create RRD file. Set array of options to parameter
            defaultCreateParams or send as parameter in create function');
        }


        if ($this->batchMode) {
            $this->batchCommands[] = [
                'command' => 'create',
                'fileName' => $fileName,
                'options' => $workOptions
            ];
        } else {
            $this->write('CREATE ' . $fileName . ' ' . implode(' ', $workOptions) . PHP_EOL);

            if ($this->autoParse) {
                return $this->readAndParse();
            }
        }
    }

    function batchBegin()
    {
        $this->write('BATCH' . PHP_EOL);
        $this->batchMode = true;
        $this->readAndParse();
    }

    function batchCommit()
    {
        $this->batchMode = false;
        $this->autoParse = false;

        foreach ($this->batchCommands as $k => $item) {

            switch ($item['command']) {

                case 'create':
                    $this->create($item['fileName'], $item['options']);
                    break;

                case 'update';
                    $this->update($item['fileName'], $item['options']);
                    break;

                case 'flush';
                    $this->flush($item['fileName']);
                    break;

                case 'forget';
                    $this->forget($item['fileName']);
                    break;

                case 'wrote';
                    $this->wrote($item['fileName']);
                    break;
            }
        }

        $this->write(".\n");

        $batchResultRaw = $this->readAndParse();

        $batchResult = explode("\n", rtrim($batchResultRaw));

        foreach ($batchResult as $k => $v) {
            $returnCommandNum = $this->parseLn($v) - 1;
            $batchItem = $this->batchCommands[$returnCommandNum];

            switch ($batchItem['command']) {
                case 'update';
                    $this->updateErrorHandler($batchItem['fileName'], $batchItem['options']);
                    break;

                case 'create':
                    if (0 < strpos($v, 'File exists')) {
                        continue;
                    }
                    throw new RrdCachedException('Error create file ' . $batchItem['fileName']);

            }
        }

        $this->autoParse = true;
    }

    function flush(string $fileName)
    {
        if ($this->batchMode) {
            $this->batchCommands[] = [
                'command' => 'flush',
                'fileName' => $fileName
            ];
        } else {
            $this->write("flush $fileName\n");

            if ($this->autoParse) {
                return $this->readAndParse();
            }
        }
    }

    function wrote(string $fileName)
    {
        if ($this->batchMode) {
            $this->batchCommands[] = [
                'command' => 'wrote',
                'fileName' => $fileName
            ];
        } else {
            $this->write("wrote $fileName\n");

            if ($this->autoParse) {
                return $this->readAndParse();
            }
        }
    }

    function flushAll()
    {
        $this->write("FLUSHALL\n");
        return $this->readAndParse();
    }

    function pending(string $fileName)
    {
        $this->write("PENDING $fileName\n");
        return $this->readAndParse();
    }

    function forget(string $fileName)
    {
        if ($this->batchMode) {
            $this->batchCommands[] = [
                'command' => 'forget',
                'fileName' => $fileName
            ];
        } else {
            $this->write("FORGET $fileName\n");

            if ($this->autoParse) {
                return $this->readAndParse();
            }
        }
    }

    function queue()
    {
        $this->write("QUEUE\n");
        return $this->readAndParse();
    }

    function fetch(string $fileName, array $options)
    {
        $this->write("FETCH $fileName " . implode(' ', $options) . "\n");
        return $this->readAndParse();
    }

    function fetchBin(string $fileName, array $options)
    {
        $this->write("FETCHBIN $fileName " . implode(' ', $options) . "\n");
        return $this->readAndParse();
    }

    function info(string $fileName)
    {
        $this->write("INFO $fileName\n");
        return $this->readAndParse();
    }

    function first(string $fileName, int $raaIndex = 0)
    {
        $this->write("FIRST $fileName $raaIndex\n");
        return $this->readAndParse();
    }

    function last(string $fileName)
    {
        $this->write("LAST $fileName\n");
        return $this->readAndParse();
    }

}