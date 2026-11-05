<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Contract;

use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Enum\Encoding;
use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Enum\OtpRateLimitScope;
use Labyrinthe\Connect\Enum\RejectionReason;
use Labyrinthe\Connect\Enum\SenderIdStatus;
use Labyrinthe\Connect\Enum\WebhookEvent;
use Labyrinthe\Connect\Internal\ErrorFactory;
use Labyrinthe\Connect\Model\Message;
use Labyrinthe\Connect\Service\OtpService;
use Labyrinthe\Connect\Service\SmsService;
use Labyrinthe\Connect\Tests\Support\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the SDK aligned with openapi/openapi.yaml (the source of truth).
 * Skipped outside the monorepo, where the spec is not available.
 */
final class OpenApiContractTest extends TestCase
{
    private const SPEC = __DIR__.'/../../../openapi/openapi.yaml';

    /** Operations implemented by the SDK. */
    private const OPERATIONS = [
        'post /sms', 'get /sms', 'post /sms/estimate', 'get /sms/{id}',
        'get /balance', 'get /sender-ids', 'post /otp/send', 'post /otp/verify',
    ];

    /** @var array<mixed> */
    private static array $spec = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::SPEC)) {
            self::markTestSkipped('openapi/openapi.yaml not found (run inside the monorepo).');
        }
        if (self::$spec === []) {
            $spec = Yaml::parseFile(self::SPEC);
            self::assertIsArray($spec);
            self::$spec = $spec;
        }
    }

    /**
     * Follows a path like "components.schemas.Message.required".
     */
    private static function at(string $path): mixed
    {
        $node = self::$spec;
        foreach (explode('.', $path) as $segment) {
            self::assertIsArray($node, $path);
            self::assertArrayHasKey($segment, $node, $path);
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @return list<string>
     */
    private static function strings(string $path): array
    {
        $values = self::at($path);
        self::assertIsArray($values, $path);

        return array_values(array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $values));
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return list<string>
     */
    private static function values(array $cases, bool $withoutUnknown = true): array
    {
        $values = array_map(static fn (\BackedEnum $c): string => (string) $c->value, $cases);

        return array_values(array_filter($values, static fn (string $v): bool => !$withoutUnknown || $v !== 'unknown'));
    }

    public function test_the_spec_version_is_supported(): void
    {
        self::assertSame('1.1.0', self::at('info.version'));
    }

    public function test_every_operation_of_the_spec_is_implemented(): void
    {
        $operations = [];
        $paths = self::at('paths');
        self::assertIsArray($paths);
        foreach ($paths as $path => $methods) {
            self::assertIsArray($methods);
            foreach (array_keys($methods) as $method) {
                if (\in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $operations[] = $method.' '.$path;
                }
            }
        }

        sort($operations);
        $expected = self::OPERATIONS;
        sort($expected);
        self::assertSame($expected, $operations);
    }

    public function test_the_default_base_url_is_the_production_server(): void
    {
        self::assertSame(Client::DEFAULT_BASE_URL, self::at('servers.0.url'));
    }

    public function test_enums_match_the_spec(): void
    {
        self::assertSame(self::strings('components.schemas.MessageStatus.enum'), self::values(MessageStatus::cases()));
        self::assertSame(self::strings('components.schemas.SenderIdStatus.enum'), self::values(SenderIdStatus::cases()));
        self::assertSame(self::strings('components.schemas.RejectionReason.enum'), self::values(RejectionReason::cases()));
        self::assertSame(self::strings('components.schemas.Message.properties.encoding.enum'), self::values(Encoding::cases()));
        self::assertSame(self::strings('components.schemas.Estimate.properties.encoding.enum'), self::values(Encoding::cases()));
        self::assertSame(self::strings('components.schemas.OtpVerifyResult.properties.reason.oneOf.1.enum'), self::values(OtpFailureReason::cases()));
        self::assertSame(self::strings('components.schemas.OtpRateLimitedError.allOf.1.properties.error.properties.details.properties.scope.enum'), self::values(OtpRateLimitScope::cases()));
        self::assertSame(self::strings('components.schemas.WebhookEnvelope.properties.event.enum'), self::values(WebhookEvent::cases(), false));

        $webhooks = self::at('webhooks');
        self::assertIsArray($webhooks);
        self::assertSame(array_keys($webhooks), self::values(WebhookEvent::cases(), false));
    }

    public function test_every_documented_error_code_is_handled(): void
    {
        $mapped = (new \ReflectionClassConstant(ErrorFactory::class, 'BY_CODE'))->getValue();
        self::assertIsArray($mapped);

        // Codes intentionally surfaced as a generic ApiException.
        $generic = ['HTTP_ERROR', 'INVALID_OTP_TEMPLATE'];

        foreach (self::strings('components.schemas.ErrorCode.examples') as $code) {
            self::assertTrue(isset($mapped[$code]) || \in_array($code, $generic, true), \sprintf('Error code %s is not handled.', $code));
        }
    }

    public function test_limits_match_the_spec(): void
    {
        self::assertSame(SmsService::MAX_BODY_LENGTH, self::at('components.schemas.SendSmsRequest.properties.body.maxLength'));
        self::assertSame(SmsService::MAX_SENDER_LENGTH, self::at('components.schemas.SendSmsRequest.properties.sender.maxLength'));
        self::assertSame(SmsService::MAX_REFERENCE_LENGTH, self::at('components.schemas.SendSmsRequest.properties.reference.maxLength'));
        self::assertSame(SmsService::MAX_RECIPIENTS, self::at('components.schemas.SendSmsRequest.properties.recipients.maxItems'));
        self::assertSame(SmsService::MAX_IDEMPOTENCY_KEY_LENGTH, self::at('components.parameters.IdempotencyKey.schema.maxLength'));
        self::assertSame(SmsService::MAX_PER_PAGE, self::at('paths./sms.get.parameters.1.schema.maximum'));
        self::assertSame(OtpService::MAX_TEMPLATE_LENGTH, self::at('components.schemas.SendOtpRequest.properties.template.maxLength'));
        self::assertSame(OtpService::MAX_CODE_LENGTH, self::at('components.schemas.VerifyOtpRequest.properties.code.maxLength'));
    }

    public function test_the_message_fixture_has_exactly_the_spec_fields_and_parses(): void
    {
        $required = self::strings('components.schemas.Message.required');
        $fixture = self::message();
        $keys = array_keys($fixture);
        sort($required);
        sort($keys);
        self::assertSame($required, $keys);

        $message = Message::fromArray($fixture);
        self::assertSame($fixture['id'], $message->id);
    }

    public function test_webhook_payload_fields_are_read(): void
    {
        $sources = [
            'MessageStatusChangedData' => \Labyrinthe\Connect\Webhook\Webhook::class,
            'CampaignCompletedData' => \Labyrinthe\Connect\Webhook\Webhook::class,
            'BalanceLowData' => \Labyrinthe\Connect\Webhook\Webhook::class,
        ];
        foreach ($sources as $schema => $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($file);
            $code = (string) file_get_contents($file);
            foreach (self::strings('components.schemas.'.$schema.'.required') as $field) {
                self::assertStringContainsString("'".$field."'", $code, \sprintf('%s.%s is not read.', $schema, $field));
            }
        }
    }
}
