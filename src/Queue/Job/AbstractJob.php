<?php


namespace Src\Queue\Job;

use Throwable;

/**
 * Abstrakte Basis-Implementierung für Queue-Jobs
 */
abstract class AbstractJob implements JobInterface
{
    /** @var string|null Eindeutige ID des Jobs */
    protected ?string $id = null;

    /** @var int|null Maximale Ausführungszeit in Sekunden */
    protected ?int $timeout = null;

    /** @var bool Ob der Job einzigartig sein soll */
    protected bool $unique = false;

    /** @var string|null Eindeutigkeitsschlüssel für den Job */
    protected ?string $uniqueKey = null;

    /**
     * Erstellt einen neuen Job
     *
     * @param string|null $id Optionale Job-ID (wird automatisch generiert, wenn nicht angegeben)
     */
    public function __construct(?string $id = null)
    {
        $this->id = $id ?? $this->generateId();
    }

    /**
     * {@inheritDoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        // Standardimplementierung: Klassenname ohne Namespace
        $className = get_class($this);
        return substr($className, strrpos($className, '\\') + 1);
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'class' => get_class($this),
            'name' => $this->getName(),
            'timeout' => $this->timeout,
            'unique' => $this->unique,
            'uniqueKey' => $this->uniqueKey,
            'data' => $this->getData()
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function fromArray(array $data): static
    {
        $className = $data['class'] ?? static::class;

        if (!class_exists($className)) {
            throw new \RuntimeException("Job-Klasse '$className' existiert nicht");
        }

        /** @var self $job */
        $job = new $className($data['id'] ?? null);

        // Jobdaten setzen
        if (isset($data['timeout'])) {
            $job->setTimeout($data['timeout']);
        }

        if (isset($data['unique']) && $data['unique']) {
            $job->unique = true;
            $job->uniqueKey = $data['uniqueKey'] ?? null;
        }

        // Spezifische Daten des Jobs setzen
        if (isset($data['data']) && is_array($data['data'])) {
            $job->setData($data['data']);
        }

        return $job;
    }

    /**
     * {@inheritDoc}
     */
    public function failed(Throwable $exception): void
    {
        // Standardimplementierung: Nichts tun
    }

    /**
     * {@inheritDoc}
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    /**
     * Setzt die maximale Ausführungszeit für den Job
     *
     * @param int|null $timeout Maximale Ausführungszeit in Sekunden
     * @return $this
     */
    public function setTimeout(?int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function isUnique(): bool
    {
        return $this->unique;
    }

    /**
     * Markiert den Job als einzigartig
     *
     * @param string|null $uniqueKey Optionaler Eindeutigkeitsschlüssel (wird aus den Jobdaten generiert, wenn nicht angegeben)
     * @return $this
     */
    public function makeUnique(?string $uniqueKey = null): self
    {
        $this->unique = true;
        $this->uniqueKey = $uniqueKey;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getUniqueKey(): string
    {
        if (!$this->isUnique()) {
            throw new \RuntimeException("Job ist nicht als einzigartig markiert");
        }

        // Wenn kein Eindeutigkeitsschlüssel angegeben wurde, einen aus den Jobdaten generieren
        if ($this->uniqueKey === null) {
            $jobData = $this->getData();
            $this->uniqueKey = md5(get_class($this) . ':' . serialize($jobData));
        }

        return $this->uniqueKey;
    }

    /**
     * Generiert eine eindeutige ID für den Job
     *
     * @return string Eindeutige ID
     */
    protected function generateId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Gibt die spezifischen Daten des Jobs zurück
     *
     * @return array Job-Daten
     */
    abstract protected function getData(): array;

    /**
     * Setzt die spezifischen Daten des Jobs
     *
     * @param array $data Job-Daten
     * @return void
     */
    abstract protected function setData(array $data): void;
}