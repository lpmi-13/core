<?php

namespace App\Tests\Client;

use App\Client\EventBridgeEventClient;
use App\Enum\EnvironmentVariable;
use App\Tests\Mock\Factory\MockEnvironmentServiceFactory;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\EventBridge\EventBridgeClient;
use AsyncAws\EventBridge\Input\PutEventsRequest;
use AsyncAws\EventBridge\Result\PutEventsResponse;
use AsyncAws\EventBridge\ValueObject\PutEventsRequestEntry;
use DateTimeImmutable;
use DateTimeInterface;
use Packages\Models\Enum\Environment;
use Packages\Models\Enum\EventBus\CoverageEvent;
use Packages\Models\Enum\EventBus\CoverageEventSource;
use Packages\Models\Enum\JobState;
use Packages\Models\Enum\Provider;
use Packages\Models\Model\Event\JobStateChange;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

class EventBridgeEventClientTest extends KernelTestCase
{
    #[DataProvider('failedEntryCountDataProvider')]
    public function testPublishEvent(int $failedEntryCount, bool $expectSuccess): void
    {
        $eventTime = new DateTimeImmutable();
        $event = [
            'type' => CoverageEvent::JOB_STATE_CHANGE->value,
            'provider' => Provider::GITHUB->value,
            'owner' => 'mock-owner',
            'repository' => 'mock-repository',
            'ref' => 'mock-ref',
            'commit' => 'mock-commit',
            'pullRequest' => null,
            'index' => 0,
            'state' => JobState::COMPLETED->value,
            'initialState' => false,
            'eventTime' => $eventTime->format(DateTimeInterface::ATOM),
        ];

        $pipelineComplete = new JobStateChange(
            Provider::GITHUB,
            'mock-owner',
            'mock-repository',
            'mock-ref',
            'mock-commit',
            null,
            0,
            JobState::COMPLETED,
            false,
            $eventTime
        );

        $mockResult = ResultMockFactory::create(
            PutEventsResponse::class,
            [
                'FailedEntryCount' => $failedEntryCount,
            ]
        );

        $mockEventBridgeClient = $this->createMock(EventBridgeClient::class);
        $mockEventBridgeClient->expects($this->once())
            ->method('putEvents')
            ->with(
                new PutEventsRequest([
                    'Entries' => [
                        new PutEventsRequestEntry([
                            'EventBusName' => 'mock-event-bus',
                            'Source' => CoverageEventSource::API->value,
                            'DetailType' => CoverageEvent::JOB_STATE_CHANGE->value,
                            'Detail' => json_encode($event),
                        ])
                    ],
                ])
            )
            ->willReturn($mockResult);


        $eventBridgeEventService = new EventBridgeEventClient(
            $mockEventBridgeClient,
            MockEnvironmentServiceFactory::getMock(
                $this,
                Environment::TESTING,
                [
                    EnvironmentVariable::EVENT_BUS->value => 'mock-event-bus'
                ]
            ),
            $this->getContainer()->get(SerializerInterface::class)
        );

        $success = $eventBridgeEventService->publishEvent(
            CoverageEvent::JOB_STATE_CHANGE,
            $pipelineComplete
        );

        $this->assertEquals($expectSuccess, $success);
    }

    public static function failedEntryCountDataProvider(): array
    {
        return [
            'No failures' => [
                0,
                true
            ],
            'One failure' => [
                1,
                false
            ],
        ];
    }
}
