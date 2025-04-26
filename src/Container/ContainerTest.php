<?php


require_once 'container.php'; // Pfad zur Container-Implementierung

use Src\Container\Container;
use Src\Container\Inject;
use Src\Container\Observable;
use Src\Container\PropertyHookAware;

/**
 * Einfache Unit-Test-Klasse für den DI-Container
 */
class ContainerTest
{
    private Container $container;

    /**
     * Test-Setup
     */
    public function setup(): void
    {
        $this->container = new Container();
        echo "Test-Setup abgeschlossen.\n";
    }

    /**
     * Führt alle Tests aus
     */
    public function runAllTests(): void
    {
        $this->setup();

        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (strpos($method, 'test') === 0 && $method !== 'testAll') {
                echo "\n» Führe Test aus: " . $method . "\n";
                try {
                    $this->$method();
                    echo "✓ Test erfolgreich\n";
                } catch (\Exception $e) {
                    echo "✗ Test fehlgeschlagen: " . $e->getMessage() . "\n";
                }
            }
        }

        echo "\nAlle Tests abgeschlossen.\n";
    }

    /**
     * Testet die Registrierung und das Abrufen von Services
     */
    public function testRegisterAndGet(): void
    {
        // Service als Objekt registrieren
        $service = new \stdClass();
        $service->name = "Test-Service";
        $this->container->register('service1', $service);

        // Service abrufen
        $retrievedService = $this->container->get('service1');
        assert($retrievedService === $service, "Service-Instanz sollte identisch sein");

        // Service über Factory registrieren
        $this->container->register('service2', function () {
            $obj = new \stdClass();
            $obj->name = "Factory-Service";
            return $obj;
        });

        // Factory-Service abrufen
        $factoryService = $this->container->get('service2');
        assert(is_object($factoryService), "Factory sollte ein Objekt zurückgeben");
        assert($factoryService->name === "Factory-Service", "Factory sollte korrekt initialisiert sein");

        // Test für has-Methode
        assert($this->container->has('service1'), "Container sollte service1 haben");
        assert(!$this->container->has('non_existent'), "Container sollte non_existent nicht haben");

        echo "Service-Registrierung und -Abruf funktionieren korrekt.\n";
    }

    /**
     * Testet Auto-Wiring über Konstruktor-Parameter
     */
    public function testConstructorAutoWiring(): void
    {
        // Testklassen für Auto-Wiring
        class ServiceA
        {
            public string $name = "ServiceA";
        }

        class ServiceB
        {
            public string $name;
            public ServiceA $serviceA;

            public function __construct(ServiceA $serviceA, string $name = "ServiceB")
            {
                $this->serviceA = $serviceA;
                $this->name = $name;
            }
        }

        // ServiceA registrieren
        $this->container->register(ServiceA::class);

        // ServiceB sollte automatisch ServiceA injiziert bekommen
        $serviceB = $this->container->get(ServiceB::class);

        assert($serviceB instanceof ServiceB, "ServiceB sollte erstellt werden");
        assert($serviceB->serviceA instanceof ServiceA, "ServiceA sollte in ServiceB injiziert werden");
        assert($serviceB->name === "ServiceB", "Name-Parameter sollte Standardwert haben");

        echo "Constructor Auto-Wiring funktioniert korrekt.\n";
    }

    /**
     * Testet Parameter-Überschreibung
     */
    public function testParameterOverride(): void
    {
        // Testklasse mit Parameter
        class ServiceWithParams
        {
            public function __construct(
                public string $name = "Default",
                public int    $value = 0
            )
            {
            }
        }

        // Parameter setzen
        $this->container->setParameters(ServiceWithParams::class, [
            'name' => 'Custom Name',
            'value' => 42
        ]);

        // Service abrufen
        $service = $this->container->get(ServiceWithParams::class);

        assert($service->name === 'Custom Name', "Name-Parameter sollte überschrieben sein");
        assert($service->value === 42, "Value-Parameter sollte überschrieben sein");

        echo "Parameter-Überschreibung funktioniert korrekt.\n";
    }

    /**
     * Testet Interface-Binding
     */
    public function testInterfaceBinding(): void
    {
        // Interface und Implementation erstellen
        interface TestServiceInterface
        {
            public function getName(): string;
        }

        class TestServiceImpl implements TestServiceInterface
        {
            public function getName(): string
            {
                return "TestServiceImpl";
            }
        }

        // Binding registrieren
        $this->container->bind(TestServiceInterface::class, TestServiceImpl::class);

        // Service über Interface abrufen
        $service = $this->container->get(TestServiceInterface::class);

        assert($service instanceof TestServiceImpl, "Service sollte eine TestServiceImpl-Instanz sein");
        assert($service->getName() === "TestServiceImpl", "Service sollte korrekt initialisiert sein");

        echo "Interface-Binding funktioniert korrekt.\n";
    }

    /**
     * Testet Property Injection
     */
    public function testPropertyInjection(): void
    {
        // Testklassen für Property Injection
        class InjectedService
        {
            public string $name = "InjectedService";
        }

        class ServiceWithInjectedProperty
        {
            #[Inject]
            public InjectedService $service;

            public function getServiceName(): string
            {
                return $this->service->name;
            }
        }

        // Service registrieren
        $this->container->register(InjectedService::class);

        // Service mit Property Injection abrufen
        $service = $this->container->get(ServiceWithInjectedProperty::class);

        assert(isset($service->service), "Property sollte injiziert sein");
        assert($service->service instanceof InjectedService, "Injizierte Property sollte vom richtigen Typ sein");
        assert($service->getServiceName() === "InjectedService", "Injizierte Property sollte korrekt initialisiert sein");

        echo "Property Injection funktioniert korrekt.\n";
    }

    /**
     * Testet Property Hooks mit spezifischem Callback
     */
    public function testPropertyHooksWithCallback(): void
    {
        // Testklasse mit Property Hook
        class ServiceWithCallbackHook
        {
            private array $log = [];

            #[Observable(callback: 'onCountChanged')]
            public int $count = 0;

            public function onCountChanged(int $oldValue, int $newValue): void
            {
                $this->log[] = "Count changed from $oldValue to $newValue";
            }

            public function getLog(): array
            {
                return $this->log;
            }
        }

        // Service erstellen
        $service = $this->container->get(ServiceWithCallbackHook::class);

        // Property ändern
        $this->container->updateProperty($service, 'count', 5);
        $this->container->updateProperty($service, 'count', 10);

        // Log überprüfen
        $log = $service->getLog();
        assert(count($log) === 2, "Es sollten 2 Log-Einträge vorhanden sein");
        assert(strpos($log[0], "Count changed from 0 to 5") !== false, "Erster Log-Eintrag sollte korrekt sein");
        assert(strpos($log[1], "Count changed from 5 to 10") !== false, "Zweiter Log-Eintrag sollte korrekt sein");

        echo "Property Hooks mit spezifischem Callback funktionieren korrekt.\n";
    }

    /**
     * Testet Property Hooks mit dem PropertyHookAware Interface
     */
    public function testPropertyHooksWithInterface(): void
    {
        // Testklasse mit PropertyHookAware Interface
        class ServiceWithInterfaceHook implements PropertyHookAware
        {
            private array $log = [];

            #[Observable]
            public string $status = "inactive";

            #[Observable]
            public int $priority = 0;

            public function onPropertyChanged(string $property, mixed $oldValue, mixed $newValue): void
            {
                $this->log[] = "Property '$property' changed from '$oldValue' to '$newValue'";
            }

            public function getLog(): array
            {
                return $this->log;
            }
        }

        // Service erstellen
        $service = $this->container->get(ServiceWithInterfaceHook::class);

        // Properties ändern
        $this->container->updateProperty($service, 'status', 'active');
        $this->container->updateProperty($service, 'priority', 1);

        // Log überprüfen
        $log = $service->getLog();
        assert(count($log) === 2, "Es sollten 2 Log-Einträge vorhanden sein");
        assert(strpos($log[0], "Property 'status' changed from 'inactive' to 'active'") !== false,
            "Erster Log-Eintrag sollte korrekt sein");
        assert(strpos($log[1], "Property 'priority' changed from '0' to '1'") !== false,
            "Zweiter Log-Eintrag sollte korrekt sein");

        echo "Property Hooks mit PropertyHookAware Interface funktionieren korrekt.\n";
    }

    /**
     * Testet die Überprüfung auf unveränderte Werte
     */
    public function testUnchangedPropertyValues(): void
    {
        // Testklasse für unveränderte Werte
        class ServiceWithUnchangedValues implements PropertyHookAware
        {
            private array $log = [];

            #[Observable]
            public int $value = 10;

            public function onPropertyChanged(string $property, mixed $oldValue, mixed $newValue): void
            {
                $this->log[] = "Property changed: $property";
            }

            public function getLog(): array
            {
                return $this->log;
            }
        }

        // Service erstellen
        $service = $this->container->get(ServiceWithUnchangedValues::class);

        // Property auf gleichen Wert setzen
        $result = $this->container->updateProperty($service, 'value', 10);

        // Überprüfen
        assert($result === false, "updateProperty sollte false zurückgeben, wenn der Wert unverändert ist");
        assert(count($service->getLog()) === 0, "Es sollten keine Property-Change-Events ausgelöst werden");

        // Property auf neuen Wert setzen
        $result = $this->container->updateProperty($service, 'value', 20);

        // Überprüfen
        assert($result === true, "updateProperty sollte true zurückgeben, wenn der Wert geändert wurde");
        assert(count($service->getLog()) === 1, "Es sollte ein Property-Change-Event ausgelöst werden");

        echo "Prüfung auf unveränderte Werte funktioniert korrekt.\n";
    }

    /**
     * Testet komplexes Zusammenspiel
     */
    public function testComplexScenario(): void
    {
        // Interface und Implementierungen
        interface MessageServiceInterface
        {
            public function sendMessage(string $message): void;
        }

        class EmailService implements MessageServiceInterface
        {
            public function __construct(
                public string $smtpServer = "localhost",
                public int    $port = 25
            )
            {
            }

            public function sendMessage(string $message): void
            {
                // Würde in einer echten Anwendung eine E-Mail versenden
            }
        }

        // Service mit verschiedenen DI-Features
        class NotificationManager implements PropertyHookAware
        {
            #[Inject]
            private MessageServiceInterface $messageService;

            #[Observable(callback: 'onModeChanged')]
            public string $mode = "normal";

            #[Observable]
            public bool $enabled = true;

            private array $notifications = [];

            public function onModeChanged(string $oldMode, string $newMode): void
            {
                $this->notifications[] = "Mode changed from $oldMode to $newMode";
            }

            public function onPropertyChanged(string $property, mixed $oldValue, mixed $newValue): void
            {
                $this->notifications[] = "Property $property changed: $oldValue -> $newValue";
            }

            public function getNotifications(): array
            {
                return $this->notifications;
            }

            public function hasMessageService(): bool
            {
                return isset($this->messageService);
            }
        }

        // Services und Binding registrieren
        $this->container->bind(MessageServiceInterface::class, EmailService::class);
        $this->container->setParameters(EmailService::class, [
            'smtpServer' => 'smtp.example.com',
            'port' => 587
        ]);

        // NotificationManager mit allen Features holen
        $notificationManager = $this->container->get(NotificationManager::class);

        // Überprüfungen
        assert($notificationManager->hasMessageService(), "MessageService sollte per Property Injection gesetzt sein");

        // Properties ändern
        $this->container->updateProperty($notificationManager, 'mode', 'urgent');
        $this->container->updateProperty($notificationManager, 'enabled', false);

        // Überprüfen der Notifications
        $notifications = $notificationManager->getNotifications();
        assert(count($notifications) === 2, "Es sollten 2 Notifications vorhanden sein");

        echo "Komplexes Szenario funktioniert korrekt.\n";
    }
}

// Tests ausführen
$tester = new ContainerTest();
$tester->runAllTests();