<?php

declare(strict_types=1);

/**
 * Minimal Magento class stubs for unit testing without a full Magento installation.
 *
 * PHPUnit mocks need the class/interface to exist so it can generate a mock.
 * These stubs define just enough structure for PHPUnit's mock builder.
 * No real Magento code is executed during unit tests.
 */

namespace Magento\Framework\App\Action {

    interface HttpGetActionInterface
    {
        public function execute(): mixed;
    }

    interface HttpPostActionInterface
    {
        public function execute(): mixed;
    }
}

namespace Magento\Framework\App {
    use Magento\Framework\DB\Adapter\AdapterInterface;

    interface RequestInterface
    {
        public function getParam(string $key, mixed $defaultValue = null): mixed;

        public function setParam(string $key, mixed $value): self;

        public function getContent(): string;

        public function getMethod(): string;

        public function has(string $key): bool;
    }

    class ResourceConnection
    {
        public function getConnection(string $resourceName = 'default'): AdapterInterface
        {
            throw new \RuntimeException('stub: getConnection() not available in tests');
        }

        public function getTableName(string $modelEntity): string
        {
            return $modelEntity;
        }
    }

    class State
    {
        public function getAreaCode(): ?string
        {
            return null;
        }
    }

    class Area
    {
        public const AREA_ADMINHTML = 'adminhtml';

        public const AREA_WEBAPI_REST = 'webapi_rest';
    }
}

namespace Magento\Framework\DB\Adapter {

    interface AdapterInterface
    {
        public function fetchOne(string $sql, array $bind = []): mixed;

        public function fetchRow(string $sql, array $bind = [], mixed $fetchMode = null): array|false;

        public function fetchAll(string $sql, array $bind = [], mixed $fetchMode = null): array;

        public function fetchPairs(string $sql, array $bind = []): array;

        public function insert(string $table, array $bind): int;

        public function update(string $table, array $bind, mixed $where = ''): int;

        public function insertOnDuplicate(string $table, array $data, array $fields = []): mixed;

        public function delete(string $table, mixed $where = ''): mixed;

        public function quoteInto(string $text, mixed $value, mixed $type = null, mixed $count = null): string;

        public function isTableExists(string $table, ?string $schemaName = null): bool;

        public function dropTable(string $tableName, ?string $schemaName = null): bool;

        public function beginTransaction(): static;

        public function commit(): static;

        public function rollBack(): static;
    }
}

namespace Magento\Framework\Controller\Result {

    class Json
    {
        public function setData(array $data): self
        {
            return $this;
        }

        public function setHttpResponseCode(int $code): self
        {
            return $this;
        }
    }

    class JsonFactory
    {
        public function create(): Json
        {
            return new Json;
        }
    }
}

namespace Magento\Framework\View\Page {

    class Config
    {
        public function getTitle(): Title
        {
            return new Title;
        }
    }

    class Title
    {
        public function prepend(mixed $text): void {}
    }
}

namespace Magento\Framework\View\Result {
    use Magento\Framework\View\Page\Config;

    class Page
    {
        public function getConfig(): Config
        {
            return new Config;
        }
    }

    class PageFactory
    {
        public function create(): Page
        {
            return new Page;
        }
    }
}

namespace Magento\Framework\Console {

    class Cli
    {
        public const RETURN_SUCCESS = 0;

        public const RETURN_FAILURE = 1;
    }

    interface CommandListInterface {}
}

namespace Magento\Framework\MessageQueue {

    interface PublisherInterface
    {
        public function publish(string $topicName, mixed $data): mixed;
    }
}

namespace Magento\Backend\App\Action {
    use Magento\Framework\App\RequestInterface;

    class Context
    {
        public function getRequest(): RequestInterface
        {
            throw new \RuntimeException('stub: getRequest() not available in tests');
        }
    }
}

namespace Magento\Backend\App {
    use Magento\Backend\App\Action\Context;
    use Magento\Framework\App\RequestInterface;
    use Magento\Framework\Message\ManagerInterface;

    abstract class Action
    {
        protected ManagerInterface $messageManager;

        public function __construct(protected Context $context)
        {
            $this->messageManager = new class implements ManagerInterface
            {
                public function addSuccessMessage(string $message, string $group = ''): static
                {
                    return $this;
                }

                public function addErrorMessage(string $message, string $group = ''): static
                {
                    return $this;
                }

                public function addWarningMessage(string $message, string $group = ''): static
                {
                    return $this;
                }

                public function addNoticeMessage(string $message, string $group = ''): static
                {
                    return $this;
                }
            };
        }

        protected function _isAllowed(): bool
        {
            return true;
        }

        public function getRequest(): RequestInterface
        {
            return $this->context->getRequest();
        }
    }
}

namespace Magento\Backend\Block\Template {

    class Context {}
}

namespace Magento\Backend\Block {
    use Magento\Backend\Block\Template\Context;
    use Magento\Framework\App\RequestInterface;
    use Magento\Framework\View\LayoutInterface;

    abstract class Template
    {
        private ?RequestInterface $_stubRequest = null;

        public function __construct(
            protected Context $context,
            protected array $data = [],
        ) {}

        public function escapeHtml(mixed $data): string
        {
            return htmlspecialchars((string) $data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        public function escapeUrl(mixed $data): string
        {
            return (string) $data;
        }

        public function escapeHtmlAttr(mixed $data): string
        {
            return htmlspecialchars((string) $data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        public function getUrl(string $route = '', array $params = []): string
        {
            $url = 'http://example.com/admin/'.ltrim($route, '/');

            foreach ($params as $key => $value) {
                $url .= '/'.$key.'/'.$value;
            }

            return $url;
        }

        public function setTestRequest(RequestInterface $request): void
        {
            $this->_stubRequest = $request;
        }

        public function getRequest(): RequestInterface
        {
            if ($this->_stubRequest !== null) {
                return $this->_stubRequest;
            }

            return new class implements RequestInterface
            {
                public function getParam(string $key, mixed $defaultValue = null): mixed
                {
                    return $defaultValue;
                }

                public function setParam(string $key, mixed $value): self
                {
                    return $this;
                }

                public function getContent(): string
                {
                    return '';
                }

                public function getMethod(): string
                {
                    return 'GET';
                }

                public function has(string $key): bool
                {
                    return false;
                }
            };
        }

        public function getLayout(): LayoutInterface
        {
            throw new \RuntimeException('stub: getLayout() not available in unit tests');
        }
    }
}

namespace Magento\Framework\App\Config {

    interface ScopeConfigInterface
    {
        public function getValue(
            string $path,
            string $scopeType = 'default',
            mixed $scopeCode = null,
        ): mixed;

        public function isSetFlag(
            string $path,
            string $scopeType = 'default',
            mixed $scopeCode = null,
        ): bool;
    }
}

namespace Magento\Framework\App {

    interface CacheInterface
    {
        public function load(string $identifier): mixed;

        public function save(string $data, string $identifier, array $tags = [], ?int $lifeTime = null): bool;

        public function remove(string $identifier): bool;

        public function clean(array $tags = []): bool;
    }
}

namespace Magento\Framework\View {

    interface LayoutInterface
    {
        public function createBlock(string $type, string $name = '', array $arguments = []): mixed;
    }
}

namespace Magento\Framework\Event {

    interface ManagerInterface
    {
        public function dispatch(string $eventName, array $data = []): void;
    }
}

namespace Magento\Framework {

    if (! \class_exists(Phrase::class, false)) {
        // non-final: Magento interceptor required (test stub mirrors Magento core)
        class Phrase
        {
            public function __construct(private readonly string $text, private readonly array $args = []) {}

            public function render(): string
            {
                return $this->text;
            }

            public function __toString(): string
            {
                return $this->text;
            }
        }
    }

    if (! \interface_exists(AuthorizationInterface::class, false)) {
        interface AuthorizationInterface
        {
            public function isAllowed(string $resource, mixed $privilege = null): bool;
        }
    }
}

namespace Magento\User\Model {

    if (! \class_exists(User::class, false)) {
        class User
        {
            public function __construct(private readonly ?int $id = null) {}

            public function getId(): ?int
            {
                return $this->id;
            }
        }
    }
}

namespace Magento\Backend\Model\Auth {
    use Magento\User\Model\User;

    if (! \class_exists(Session::class, false)) {
        class Session
        {
            public function __construct(private readonly ?User $user = null) {}

            public function getUser(): ?User
            {
                return $this->user;
            }
        }
    }
}

namespace Magento\Authorization\Model {

    if (! \interface_exists(UserContextInterface::class, false)) {
        interface UserContextInterface
        {
            public const USER_TYPE_INTEGRATION = 1;

            public const USER_TYPE_ADMIN = 2;

            public const USER_TYPE_CUSTOMER = 3;

            public const USER_TYPE_GUEST = 4;

            public function getUserId(): ?int;

            public function getUserType(): ?int;
        }
    }
}

namespace Magento\Framework\Exception {
    use Magento\Framework\Phrase;

    if (! \class_exists(LocalizedException::class, false)) {
        // non-final: Magento interceptor required (test stub mirrors Magento core)
        class LocalizedException extends \Exception
        {
            public function __construct(Phrase $phrase, ?\Throwable $cause = null, string $code = '')
            {
                parent::__construct((string) $phrase, 0, $cause);
            }
        }
    }

    if (! \class_exists(InputException::class, false)) {
        // non-final: Magento interceptor required (test stub mirrors Magento core)
        class InputException extends LocalizedException {}
    }
}

namespace Magento\Framework\Webapi {
    use Magento\Framework\Phrase;

    if (! \class_exists(Exception::class, false)) {
        // non-final: Magento interceptor required (test stub mirrors Magento core)
        class Exception extends \Exception
        {
            public const HTTP_BAD_REQUEST = 400;

            public const HTTP_UNAUTHORIZED = 401;

            public const HTTP_FORBIDDEN = 403;

            public const HTTP_NOT_FOUND = 404;

            public const HTTP_INTERNAL_ERROR = 500;

            private int $httpCode;

            public function __construct(Phrase $phrase, int $code = 0, int $httpCode = 400)
            {
                parent::__construct((string) $phrase, $code);
                $this->httpCode = $httpCode;
            }

            public function getHttpCode(): int
            {
                return $this->httpCode;
            }
        }
    }
}

namespace PhpClaw {
    use PhpClaw\Agent\AgentResponse;
    use PhpClaw\Agent\Conversation;
    use PhpClaw\Agent\ConversationTurn;
    use PhpClaw\Contracts\ClawInterface;
    use PhpClaw\Memory\Contracts\MemoryInterface;

    if (! \class_exists(ClawBuilder::class, false)) {
        // non-final: mirrors the real ClawBuilder shape needed by PhpClawFactory
        class ClawBuilder
        {
            private string $apiKey = '';

            private string $provider = '';

            private string $model = '';

            private bool $storeMessages = true;

            private int $maxIterations = 20;

            private array $tools = [];

            private ?object $memory = null;

            private string $systemPrompt = '';

            private string $cloudKey = '';

            private array $cloudDisable = [];

            public function apiKey(string $k): self
            {
                $this->apiKey = $k;

                return $this;
            }

            public function provider(string $p): self
            {
                $this->provider = $p;

                return $this;
            }

            public function model(string $m): self
            {
                $this->model = $m;

                return $this;
            }

            public function cloudKey(string $k): self
            {
                $this->cloudKey = $k;

                return $this;
            }

            public function cloudDisable(array $d): self
            {
                $this->cloudDisable = $d;

                return $this;
            }

            public function cloudSigningSecret(string $s): self
            {
                return $this;
            }

            public function storeMessages(bool $f = true): self
            {
                $this->storeMessages = $f;

                return $this;
            }

            public function maxIterations(int $c): self
            {
                $this->maxIterations = $c;

                return $this;
            }

            public function maxToolsPerTurn(int $n): self
            {
                return $this;
            }

            public function tools(array $t): self
            {
                $this->tools = $t;

                return $this;
            }

            public function memory(object $m): self
            {
                $this->memory = $m;

                return $this;
            }

            public function systemPrompt(string $p): self
            {
                $this->systemPrompt = $p;

                return $this;
            }

            public function providerOverride(object $p): self
            {
                return $this;
            }

            public function withRemoteSkills(string $url): self
            {
                return $this;
            }

            private ?object $approvalGate = null;

            public function approvalGate(object $gate): self
            {
                $this->approvalGate = $gate;

                return $this;
            }

            public function build(): Claw
            {
                return new Claw(
                    apiKey: $this->apiKey,
                    provider: $this->provider,
                    model: $this->model,
                    storeMessages: $this->storeMessages,
                    maxIterations: $this->maxIterations,
                    memory: $this->memory,
                    systemPrompt: $this->systemPrompt,
                    tools: $this->tools,
                    approvalGate: $this->approvalGate,
                );
            }
        }
    }

    if (! \class_exists(ClawConfig::class, false)) {
        class ClawConfig
        {
            public const DEFAULT_MAX_ITERATIONS = 20;

            public string $apiKey = '';

            public string $provider = '';

            public string $model = '';
        }
    }

    if (! \class_exists(Claw::class, false)) {
        class Claw implements ClawInterface
        {
            private ?MemoryInterface $memory;

            private bool $storeMessages;

            private array $tools;

            private ?object $approvalGate;

            public function __construct(
                string $apiKey = '',
                string $provider = '',
                string $model = '',
                bool $storeMessages = true,
                int $maxIterations = 20,
                ?MemoryInterface $memory = null,
                string $systemPrompt = '',
                array $tools = [],
                ?object $approvalGate = null,
            ) {
                $this->memory = $memory;
                $this->storeMessages = $storeMessages;
                $this->tools = $tools;
                $this->approvalGate = $approvalGate;
            }

            public function send(string $message): AgentResponse
            {
                return new AgentResponse('', '', '', 0, 0);
            }

            public function stream(string $message, callable $onToken): AgentResponse
            {
                return new AgentResponse('', '', '', 0, 0);
            }

            public function conversation(string $id = '', array $metadata = []): Conversation
            {
                return new Conversation($id, $metadata);
            }

            public function sendInConversation(Conversation $conversation, string $message): ConversationTurn
            {
                return new ConversationTurn(new AgentResponse('', '', '', 0, 0), $conversation);
            }

            public function streamInConversation(Conversation $conversation, string $message, callable $onToken, ?callable $beforePersist = null): ConversationTurn
            {
                return new ConversationTurn(new AgentResponse('', '', '', 0, 0), $conversation);
            }

            public function memory(): ?MemoryInterface
            {
                return $this->memory;
            }

            public function storeMessages(): bool
            {
                return $this->storeMessages;
            }

            public function tools(): array
            {
                return $this->tools;
            }

            public function approvalGate(): ?object
            {
                return $this->approvalGate;
            }

            public static function builder(): ClawBuilder
            {
                return new ClawBuilder;
            }
        }
    }
}

namespace PhpClaw\Cloud {

    if (! \class_exists(CloudManager::class, false)) {
        final class CloudManager
        {
            public static function boot(string $key = '', array $disable = []): void {}
        }
    }
}

namespace PhpClaw\Providers {

    if (! \class_exists(ProviderCatalogue::class, false)) {
        final class ProviderCatalogue
        {
            public static function all(): array
            {
                return [
                    'anthropic' => ['label' => 'Anthropic (Claude)', 'class' => ''],
                    'openai' => ['label' => 'OpenAI (GPT)',        'class' => ''],
                    'groq' => ['label' => 'Groq',               'class' => ''],
                    'gemini' => ['label' => 'Google Gemini',       'class' => ''],
                    'mistral' => ['label' => 'Mistral',            'class' => ''],
                    'deepseek' => ['label' => 'DeepSeek',           'class' => ''],
                    'ollama' => ['label' => 'Ollama (local)',      'class' => ''],
                    'bedrock' => ['label' => 'AWS Bedrock',         'class' => ''],
                ];
            }

            public static function register(string $key, string $label, string $class): void {}

            public static function reset(): void {}

            public static function boot(): void {}

            public static function keys(): array
            {
                return array_keys(self::all());
            }

            public static function find(string $key): ?array
            {
                return self::all()[$key] ?? null;
            }

            public static function activateFromSettings(array $settings): array
            {
                return [];
            }
        }
    }
}

namespace PhpClaw\Tools {

    if (! \class_exists(ToolProfileResolver::class, false)) {
        // non-final: test stub mirrors the core class shape
        class ToolProfileResolver
        {
            public static function resolve(string $provider, string $model): string
            {
                return \in_array($provider, ['ollama', 'groq'], true) ? 'minimal' : 'full';
            }

            public static function maxTools(string $profile): int
            {
                return $profile === 'minimal' ? 5 : 0;
            }

            public static function filter(array $tools, array $deny = [], array $groups = []): array
            {
                return $tools;
            }
        }
    }
}

namespace Psr\Log {

    if (! \interface_exists(LoggerInterface::class, false)) {
        interface LoggerInterface
        {
            public function emergency(string|\Stringable $message, array $context = []): void;

            public function alert(string|\Stringable $message, array $context = []): void;

            public function critical(string|\Stringable $message, array $context = []): void;

            public function error(string|\Stringable $message, array $context = []): void;

            public function warning(string|\Stringable $message, array $context = []): void;

            public function notice(string|\Stringable $message, array $context = []): void;

            public function info(string|\Stringable $message, array $context = []): void;

            public function debug(string|\Stringable $message, array $context = []): void;

            public function log($level, string|\Stringable $message, array $context = []): void;
        }
    }
}

namespace Magento\Framework\Setup {
    use Magento\Framework\DB\Adapter\AdapterInterface;

    interface ModuleDataSetupInterface
    {
        public function getConnection(string $resourceName = 'default'): AdapterInterface;

        public function getTable(string $tableName, string $resourceName = 'default'): string;
    }
}

namespace Magento\Framework\Setup\Patch {

    interface DataPatchInterface
    {
        public function apply(): mixed;

        public static function getDependencies(): array;

        public function getAliases(): array;
    }
}

namespace Magento\Framework\Encryption {

    interface EncryptorInterface
    {
        public function encrypt(string $data): string;

        public function decrypt(string $data): string;

        public function hash(string $data, mixed $version = false): string;

        public function isValidHash(string $password, string $hash): bool;
    }
}

namespace Magento\Framework\App\Config\Storage {

    interface WriterInterface
    {
        public function save(string $path, mixed $value, string $scope = 'default', int $scopeId = 0): void;

        public function delete(string $path, string $scope = 'default', int $scopeId = 0): void;
    }
}

namespace Magento\Framework\App\Cache {

    interface TypeListInterface
    {
        public function cleanType(string $typeCode): void;

        public function invalidate(mixed $typeCode): void;

        public function getTypes(): array;

        public function getInvalidated(): array;
    }
}

namespace Magento\Framework\Controller\Result {

    class Redirect
    {
        public function setPath(string $path, array $params = []): static
        {
            return $this;
        }

        public function setUrl(string $url): static
        {
            return $this;
        }
    }

    class RedirectFactory
    {
        public function create(): Redirect
        {
            return new Redirect;
        }
    }
}

namespace Magento\Framework\Message {

    interface ManagerInterface
    {
        public function addSuccessMessage(string $message, string $group = ''): static;

        public function addErrorMessage(string $message, string $group = ''): static;

        public function addWarningMessage(string $message, string $group = ''): static;

        public function addNoticeMessage(string $message, string $group = ''): static;
    }
}

namespace Magento\Framework\Setup {
    use Magento\Framework\DB\Adapter\AdapterInterface;

    interface SchemaSetupInterface
    {
        public function startSetup(): static;

        public function endSetup(): static;

        public function getConnection(string $resourceName = 'default'): AdapterInterface;

        public function getTable(string $tableName, string $resourceName = 'default'): string;

        public function tableExists(string $table): bool;
    }

    interface ModuleContextInterface
    {
        public function getVersion(): string;
    }

    interface UninstallInterface
    {
        public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void;
    }
}

namespace Magento\Framework\Data {

    interface OptionSourceInterface
    {
        public function toOptionArray(): array;
    }
}

namespace Magento\Framework {

    if (! \class_exists(DataObject::class, false)) {
        // non-final: Magento interceptor required (test stub mirrors Magento core)
        class DataObject
        {
            private array $data = [];

            public function __construct(array $data = [])
            {
                $this->data = $data;
            }

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function setData(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }
        }
    }

    if (! \class_exists('Magento\Framework\DataObjectFactory', false)) {
        class DataObjectFactory
        {
            public function create(array $data = []): DataObject
            {
                return new DataObject($data);
            }
        }
    }
}
