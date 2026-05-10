<?php

declare(strict_types=1);

namespace App\PortalAssinaturas;

//A API pode responder em formatos diferentes. Essa classe tenta extrair os dados principais.
final class CreateBatchResponseNormalizer
{
    private readonly SignerNormalizer $signerNormalizer;

    public function __construct(?SignerNormalizer $signerNormalizer = null)
    {
        $this->signerNormalizer = $signerNormalizer ?? new SignerNormalizer();
    }

    public function normalize(array $response): array
    {
        $document = $response['documents'][0] ?? $response['document'] ?? [];
        $attendee = $response['attendees'][0] ?? [];
        $signUrl = $document['signUrl'] ?? $response['signUrl'] ?? $response['batchSignUrl'] ?? $attendee['signUrl'] ?? null;

        return [
            'portal_document_id' => $document['id'] ?? $response['id'] ?? null,
            'document_key' => $document['key']
                ?? $document['chave']
                ?? $response['key']
                ?? $response['chave']
                ?? $attendee['key']
                ?? $attendee['chave']
                ?? $this->inferDocumentKeyFromSignUrl($signUrl),
            'sign_url' => $signUrl,
            'errors' => $response['errors'] ?? [],
        ];
    }

    public function mergeSignerLinks(array $signers, array $response): array
    {
        $attendees = $this->extractAttendees($response);

        foreach ($signers as $index => $signer) {
            foreach ($attendees as $attendee) {
                if (!is_array($attendee)) {
                    continue;
                }

                $attendeeEmail = strtolower(trim((string) ($attendee['email'] ?? $attendee['mail'] ?? '')));
                $attendeeCpf = $this->signerNormalizer->sanitizeCpf((string) ($attendee['individualIdentificationCode'] ?? $attendee['cpf'] ?? ''));
                $matchesEmail = $attendeeEmail !== '' && $attendeeEmail === $signer['email'];
                $matchesCpf = $attendeeCpf !== null && $attendeeCpf === $signer['cpf'];

                if (!$matchesEmail && !$matchesCpf) {
                    continue;
                }

                $signers[$index]['sign_url'] = $attendee['signUrl']
                    ?? $attendee['link']
                    ?? $attendee['linkFrame']
                    ?? null;
                break;
            }
        }

        return $signers;
    }

    private function extractAttendees(array $response): array
    {
        $document = $response['documents'][0] ?? $response['document'] ?? [];
        $attendees = [];

        foreach ([$response['attendees'] ?? null, $document['attendees'] ?? null] as $candidate) {
            if (is_array($candidate)) {
                $attendees = array_merge($attendees, $candidate);
            }
        }

        if (isset($response['steps']) && is_array($response['steps'])) {
            foreach ($response['steps'] as $step) {
                if (isset($step['attendees']) && is_array($step['attendees'])) {
                    $attendees = array_merge($attendees, $step['attendees']);
                }
            }
        }

        return $attendees;
    }

    private function inferDocumentKeyFromSignUrl(?string $signUrl): ?string
    {
        if (!is_string($signUrl) || trim($signUrl) === '') {
            return null;
        }

        $query = parse_url($signUrl, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);

        foreach (['key', 'documentKey', 'document_key'] as $candidate) {
            $value = $params[$candidate] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
