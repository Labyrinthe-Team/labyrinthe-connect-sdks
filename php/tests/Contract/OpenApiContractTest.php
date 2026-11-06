<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Contract;

use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Enum\Encoding;
use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Enum\OtpCodeType;
use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Enum\OtpRateLimitScope;
use Labyrinthe\Connect\Enum\OtpWebhookStatus;
use Labyrinthe\Connect\Enum\RejectionReason;
use Labyrinthe\Connect\Enum\SenderIdStatus;
use Labyrinthe\Connect\Enum\WebhookEvent;
use Labyrinthe\Connect\Internal\ErrorFactory;
use Labyrinthe\Connect\Internal\Transport;
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
        self::assertSame('1.2.0', self::at('info.version'));
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
        self::assertSame(self::strings('components.schemas.Encoding.enum'), self::values(Encoding::cases()));
        self::assertSame('#/components/schemas/Encoding', self::at('components.schemas.Message.properties.encoding.$ref'));
        self::assertSame('#/components/schemas/Encoding', self::at('components.schemas.Estimate.properties.encoding.$ref'));
        self::assertSame(self::strings('components.schemas.OtpVerifyResult.properties.reason.oneOf.1.enum'), self::values(OtpFailureReason::cases()));
        self::assertSame(self::strings('components.schemas.OtpRateLimitedError.allOf.1.properties.error.properties.details.properties.scope.enum'), self::values(OtpRateLimitScope::cases()));
        self::assertSame(self::strings('components.schemas.OtpSendResult.properties.code_type.enum'), self::values(OtpCodeType::cases()));
        self::assertSame(self::strings('components.schemas.OtpWebhookData.properties.status.enum'), self::values(OtpWebhookStatus::cases()));
        foreach (self::strings('components.schemas.OtpWebhookData.properties.reason.oneOf.1.enum') as $reason) {
            self::assertNotSame(OtpFailureReason::Unknown, OtpFailureReason::fromApi($reason), \sprintf('OTP webhook reason %s is not known.', $reason));
        }
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
        self::assertSame(OtpService::MAX_IDEMPOTENCY_KEY_LENGTH, self::at('components.parameters.IdempotencyKey.schema.maxLength'));
        self::assertSame(OtpService::MAX_WEB_OTP_DOMAIN_LENGTH, self::at('components.schemas.SendOtpRequest.properties.web_otp_domain.maxLength'));
        self::assertSame(OtpService::WEB_OTP_DOMAIN_PATTERN, self::at('components.schemas.SendOtpRequest.properties.web_otp_domain.pattern'));
        $requestIdPattern = self::at('components.parameters.RequestId.schema.pattern');
        self::assertIsString($requestIdPattern);
        self::assertSame(Transport::REQUEST_ID_PATTERN, '/'.$requestIdPattern.'/');
    }

    public function test_idempotency_key_and_request_id_are_accepted_where_the_spec_says(): void
    {
        foreach (['/sms', '/otp/send'] as $path) {
            $parameters = self::at('paths.'.$path.'.post.parameters');
            self::assertIsArray($parameters);
            self::assertContains(['$ref' => '#/components/parameters/IdempotencyKey'], $parameters, $path);
        }

        $paths = self::at('paths');
        self::assertIsArray($paths);
        foreach ($paths as $path => $methods) {
            self::assertIsArray($methods);
            foreach ($methods as $method => $operation) {
                self::assertIsArray($operation);
                $parameters = $operation['parameters'] ?? [];
                self::assertIsArray($parameters);
                self::assertContains(['$ref' => '#/components/parameters/RequestId'], $parameters, $method.' '.$path);
            }
        }
    }

    public function test_strip_accents_is_offered_on_every_operation_that_accepts_it(): void
    {
        foreach (['SendSmsRequest', 'EstimateSmsRequest', 'SendOtpRequest'] as $schema) {
            self::assertSame(['$ref' => '#/components/schemas/StripAccents'], self::at('components.schemas.'.$schema.'.properties.strip_accents'), $schema);
        }

        foreach ([[SmsService::class, 'send'], [SmsService::class, 'sendMany'], [SmsService::class, 'estimate'], [OtpService::class, 'send']] as [$class, $method]) {
            $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionMethod($class, $method))->getParameters());
            self::assertContains('stripAccents', $names, $class.'::'.$method);
            self::assertContains('requestId', $names, $class.'::'.$method);
        }
        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionMethod(OtpService::class, 'send'))->getParameters());
        self::assertContains('webOtpDomain', $names);
        self::assertContains('idempotencyKey', $names);
    }

    public function test_otp_results_read_every_required_field(): void
    {
        $sources = [
            'OtpSendResult' => \Labyrinthe\Connect\Model\OtpSendResult::class,
            'OtpVerifyResult' => \Labyrinthe\Connect\Model\OtpVerifyResult::class,
            'Estimate' => \Labyrinthe\Connect\Model\Estimate::class,
        ];
        foreach ($sources as $schema => $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($file);
            $code = (string) file_get_contents($file);
            foreach (self::strings('components.schemas.'.$schema.'.required') as $field) {
                self::assertStringContainsString("'".$field."'", $code, \sprintf('%s.%s is not read.', $schema, $field));
            }
        }

        $fixture = self::otpSendData();
        $keys = array_keys($fixture);
        $properties = self::at('components.schemas.OtpSendResult.properties');
        self::assertIsArray($properties);
        $expected = array_keys($properties);
        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys, 'The OTP fixture has every field of the spec.');
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
            'OtpWebhookData' => \Labyrinthe\Connect\Webhook\Webhook::class,
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
