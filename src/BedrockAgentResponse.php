<?php

namespace App;

class BedrockAgentResponse
{
    private string $sessionId;
    private string $text;
    /** @var list<string> */
    private array $citations;
    private ?string $guardrailAction;
    private array $raw;

    /**
     * @param list<string> $citations
     */
    private function __construct(string $sessionId, string $text, array $citations, ?string $guardrailAction, array $raw)
    {
        $this->sessionId = $sessionId;
        $this->text = $text;
        $this->citations = $citations;
        $this->guardrailAction = $guardrailAction;
        $this->raw = $raw;
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromApiPayload(array $payload): self
    {
        $text = '';
        if (isset($payload['output']) && is_array($payload['output'])) {
            $text = self::extractText($payload['output']);
        }
        $sessionId = (string)($payload['sessionId'] ?? '');
        $guardrailAction = isset($payload['guardrailAction']) ? (string)$payload['guardrailAction'] : null;
        $citations = self::extractCitations($payload);

        return new self($sessionId, $text, $citations, $guardrailAction, $payload);
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @return list<string>
     */
    public function getCitations(): array
    {
        return $this->citations;
    }

    public function getGuardrailAction(): ?string
    {
        return $this->guardrailAction;
    }

    /**
     * @return array<mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * @param array<mixed> $output
     */
    private static function extractText(array $output): string
    {
        if (isset($output['text'])) {
            return (string)$output['text'];
        }

        if (isset($output['markdown'])) {
            return (string)$output['markdown'];
        }

        if (isset($output['conversation'])) {
            return (string)$output['conversation'];
        }

        foreach ($output as $value) {
            if (is_array($value)) {
                $nested = self::extractText($value);
                if ($nested !== '') {
                    return $nested;
                }
            } elseif (is_string($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $payload
     * @return list<string>
     */
    private static function extractCitations(array $payload): array
    {
        if (!isset($payload['citations']) || !is_array($payload['citations'])) {
            return [];
        }

        $results = [];

        foreach ($payload['citations'] as $citation) {
            if (!is_array($citation)) {
                continue;
            }

            if (!isset($citation['retrievedReferences']) || !is_array($citation['retrievedReferences'])) {
                continue;
            }

            foreach ($citation['retrievedReferences'] as $reference) {
                if (!is_array($reference)) {
                    continue;
                }

                $source = self::resolveSourceFromReference($reference);
                if ($source !== null) {
                    $results[$source] = true;
                }
            }
        }

        return array_keys($results);
    }

    /**
     * @param array<mixed> $reference
     */
    private static function resolveSourceFromReference(array $reference): ?string
    {
        if (isset($reference['location']) && is_array($reference['location'])) {
            $location = $reference['location'];
            if (isset($location['webLocation']['url'])) {
                return (string)$location['webLocation']['url'];
            }
            if (isset($location['s3Location']['uri'])) {
                return (string)$location['s3Location']['uri'];
            }
            if (isset($location['customDocumentLocation']['id'])) {
                return (string)$location['customDocumentLocation']['id'];
            }
            if (isset($location['sharePointLocation']['url'])) {
                return (string)$location['sharePointLocation']['url'];
            }
            if (isset($location['salesforceLocation']['url'])) {
                return (string)$location['salesforceLocation']['url'];
            }
            if (isset($location['confluenceLocation']['url'])) {
                return (string)$location['confluenceLocation']['url'];
            }
        }

        if (isset($reference['metadata']) && is_array($reference['metadata'])) {
            $metadata = $reference['metadata'];
            foreach (['source', 'documentId', 'title'] as $key) {
                if (isset($metadata[$key]) && is_string($metadata[$key])) {
                    return $metadata[$key];
                }
            }
        }

        if (isset($reference['content']['text'])) {
            $snippet = (string)$reference['content']['text'];
            if (function_exists('mb_substr')) {
                $trimmed = mb_substr($snippet, 0, 120);
                if (mb_strlen($snippet) > 120) {
                    $trimmed .= '…';
                }
                return $trimmed;
            }
            $trimmed = substr($snippet, 0, 120);
            if (strlen($snippet) > 120) {
                $trimmed .= '…';
            }
            return $trimmed;
        }

        return null;
    }
}
