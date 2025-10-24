<?php

namespace App;

use JsonException;
use RuntimeException;

class BedrockAgentClient
{
    private string $region;
    private string $knowledgeBaseId;
    private string $modelArn;
    private ?string $profile;
    private string $cliBinary;

    public function __construct(string $region, string $knowledgeBaseId, string $modelArn, ?string $profile = null, string $cliBinary = 'aws')
    {
        $this->region = $region;
        $this->knowledgeBaseId = $knowledgeBaseId;
        $this->modelArn = $modelArn;
        $this->profile = $profile ?: null;
        $this->cliBinary = $cliBinary;
    }

    public static function fromEnvironment(): ?self
    {
        $region = self::valueFromEnv(['BEDROCK_REGION', 'AWS_REGION', 'AWS_DEFAULT_REGION']);
        $knowledgeBaseId = self::valueFromEnv(['BEDROCK_KNOWLEDGE_BASE_ID']);
        $modelArn = self::valueFromEnv(['BEDROCK_MODEL_ARN', 'BEDROCK_INFERENCE_PROFILE_ARN']);
        if ($region === null || $knowledgeBaseId === null || $modelArn === null) {
            return null;
        }

        $profile = self::valueFromEnv(['BEDROCK_PROFILE', 'AWS_PROFILE']);
        $cliBinary = self::valueFromEnv(['BEDROCK_AWS_CLI']) ?? 'aws';

        return new self($region, $knowledgeBaseId, $modelArn, $profile, $cliBinary);
    }

    /**
     * @throws RuntimeException
     */
    public function ask(string $prompt, ?string $sessionId = null): BedrockAgentResponse
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Prompt must not be empty.');
        }

        try {
            $inputJson = json_encode(['text' => $prompt], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $configurationJson = json_encode(
                [
                    'type' => 'KNOWLEDGE_BASE',
                    'knowledgeBaseConfiguration' => [
                        'knowledgeBaseId' => $this->knowledgeBaseId,
                        'modelArn' => $this->modelArn,
                    ],
                ],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode request payload: ' . $exception->getMessage());
        }

        $commandParts = [
            $this->cliBinary,
            'bedrock-agent-runtime',
            'retrieve-and-generate',
            '--region',
            $this->region,
            '--input',
            $inputJson,
            '--retrieve-and-generate-configuration',
            $configurationJson,
            '--output',
            'json',
        ];

        if ($sessionId !== null && $sessionId !== '') {
            $commandParts[] = '--session-id';
            $commandParts[] = $sessionId;
        }

        if ($this->profile !== null && $this->profile !== '') {
            $commandParts[] = '--profile';
            $commandParts[] = $this->profile;
        }

        $command = $this->buildCommandString($commandParts);
        [$exitCode, $stdout, $stderr] = $this->runCommand($command);

        if ($exitCode !== 0) {
            $message = trim($stderr) !== '' ? trim($stderr) : 'AWS CLI returned an error.';
            if (str_contains($message, 'command not found')) {
                $message = 'AWS CLI is not installed or not accessible via PATH.';
            }
            throw new RuntimeException($message);
        }

        $data = json_decode($stdout, true);
        if (!is_array($data)) {
            throw new RuntimeException('Failed to parse Bedrock response JSON.');
        }

        return BedrockAgentResponse::fromApiPayload($data);
    }

    private function buildCommandString(array $parts): string
    {
        $escaped = [];
        foreach ($parts as $part) {
            $escaped[] = escapeshellarg((string)$part);
        }

        return implode(' ', $escaped);
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function runCommand(string $command): array
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, null, null);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start AWS CLI process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);

        return [
            $exitCode ?? 1,
            $stdout !== false ? $stdout : '',
            $stderr !== false ? $stderr : '',
        ];
    }

    /**
     * @param list<string> $names
     */
    private static function valueFromEnv(array $names): ?string
    {
        foreach ($names as $name) {
            $value = getenv($name);
            if ($value !== false && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
