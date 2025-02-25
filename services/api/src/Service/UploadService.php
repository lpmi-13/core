<?php

namespace App\Service;

use App\Exception\SigningException;
use App\Model\SignedUrl;
use App\Model\SigningParameters;
use AsyncAws\S3\Input\PutObjectRequest;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

class UploadService
{
    private const TARGET_BUCKET = 'coverage-ingest-%s';

    private const EXPIRY_MINUTES = 5;

    /**
     * @param SerializerInterface&NormalizerInterface&DenormalizerInterface $serializer
     */
    public function __construct(
        private readonly UploadSignerService $uploadSignerService,
        private readonly EnvironmentService $environmentService,
        private readonly UniqueIdGeneratorService $uniqueIdGeneratorService,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $uploadLogger
    ) {
    }

    /**
     * @throws SigningException
     */
    public function getSigningParametersFromRequest(Request $request): SigningParameters
    {
        $body = $request->toArray();

        if (!isset($body['data']) || !is_array($body['data'])) {
            $this->uploadLogger->info(
                'No data key found in request body.',
                [
                    'parameters' => $body
                ]
            );

            throw SigningException::invalidParameters(
                new InvalidArgumentException('No data key found in request body.')
            );
        }

        /** @var array{ data: array<array-key, mixed> } $body */
        $parameters = $body['data'];

        $this->uploadLogger->info(
            'Beginning to generate signed url for upload request.',
            [
                'parameters' => $parameters
            ]
        );

        try {
            return $this->serializer->denormalize(
                $parameters,
                SigningParameters::class
            );
        } catch (SigningException $exception) {
            $this->uploadLogger->error(
                $exception->getMessage(),
                [
                    'parameters' => $parameters
                ]
            );

            throw $exception;
        }
    }

    public function buildSignedUploadUrl(SigningParameters $signingParameters): SignedUrl
    {
        $uploadId = $this->uniqueIdGeneratorService->generate();

        $uploadKey = sprintf(
            '%s/%s/%s/%s.%s',
            $signingParameters->getOwner(),
            $signingParameters->getRepository(),
            $signingParameters->getCommit(),
            $uploadId,
            pathinfo($signingParameters->getFileName(), PATHINFO_EXTENSION)
        );

        $input = $this->getSignedPutRequest(
            sprintf(
                self::TARGET_BUCKET,
                $this->environmentService->getEnvironment()->value
            ),
            $uploadKey,
            $uploadId,
            $signingParameters
        );

        $expiry = new DateTimeImmutable(sprintf('+%s min', self::EXPIRY_MINUTES));

        return $this->uploadSignerService->sign($uploadId, $input, $expiry);
    }

    private function getSignedPutRequest(
        string $bucket,
        string $key,
        string $uploadId,
        SigningParameters $signingParameters
    ): PutObjectRequest {
        /** @var array<string, string> $metadata */
        $metadata = array_merge(
            (array)$this->serializer->normalize($signingParameters),
            [
                'uploadId' => $uploadId,
                'parent' => $this->serializer->serialize(
                    $signingParameters->getParent(),
                    'json'
                )
            ]
        );

        return new PutObjectRequest([
            'Bucket' => $bucket,
            'Key' => $key,
            'Metadata' => $metadata
        ]);
    }
}
