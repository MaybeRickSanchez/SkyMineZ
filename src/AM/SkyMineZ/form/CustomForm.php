<?php

declare(strict_types=1);

namespace AM\SkyMineZ\form;

use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

/**
 * A `type: custom_form` window: a stack of inputs of different kinds.
 *
 * Supported elements, in the order the Bedrock client expects them:
 *
 *  - label       read-only text, the response is always null
 *  - toggle      on/off switch, the response is bool
 *  - slider      numeric range, the response is int or float
 *  - step_slider discrete list of labels, the response is the chosen index
 *  - dropdown    same as step_slider but drawn as a drop-down
 *  - input       free text, the response is a string
 *
 * The response arrives as a list in element order. It is validated against the
 * per-element validator and then re-keyed by label, so the closure receives an
 * associative array of `label => value` instead of positional indexes that
 * silently shift whenever an element is inserted.
 */
class CustomForm extends Form
{
    public const TYPE_LABEL = 'label';
    public const TYPE_TOGGLE = 'toggle';
    public const TYPE_SLIDER = 'slider';
    public const TYPE_STEP_SLIDER = 'step_slider';
    public const TYPE_DROPDOWN = 'dropdown';
    public const TYPE_INPUT = 'input';

    /** @var list<array<string, mixed>> */
    private array $elements = [];

    /** @var list<int|string> */
    private array $labelMap = [];

    /** @var list<callable(mixed): bool> */
    private array $validators = [];

    /**
     * @param callable(Player, mixed): void|null $callable
     */
    public function __construct(
        ?callable $callable = null,
        ?callable $onCompletion = null
    ) {
        parent::__construct($callable, $onCompletion);

        $this->data['type'] = 'custom_form';
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(): array
    {
        return ['content' => $this->elements];
    }

    public function processData(&$data): void
    {
        if ($data === null) {
            return;
        }

        if (!is_array($data)) {
            throw new FormValidationException(
                'Expected an array response, got ' . gettype($data)
            );
        }

        if (count($data) !== count($this->validators)) {
            throw new FormValidationException(
                'Expected an array response with the size '
                . count($this->validators)
                . ', got ' . count($data)
            );
        }

        $remapped = [];

        foreach (
            $data as $index => $value
        ) {
            $validator = $this->validators[$index] ?? null;

            if ($validator === null) {
                throw new FormValidationException(
                    "Invalid element $index"
                );
            }

            if (!$validator($value)) {
                throw new FormValidationException(
                    'Invalid type given for element '
                    . ($this->labelMap[$index] ?? $index)
                );
            }

            $remapped[$this->labelMap[$index]] = $value;
        }

        $data = $remapped;
    }

    public function addInput(
        string $text,
        string $placeholder = '',
        ?string $default = null,
        int|string|null $label = null
    ): static {
        $content = [
            'type' => self::TYPE_INPUT,
            'text' => $text,
            'placeholder' => $placeholder
        ];

        if ($default !== null) {
            $content['default'] = $default;
        }

        return $this->push(
            $content,
            $label,
            static fn(
                mixed $v
            ): bool => is_string($v)
        );
    }

    /**
     * @param array<string, mixed> $content
     * @param callable(mixed): bool $validator
     */
    private function push(
        array $content,
        int|string|null $label,
        callable $validator
    ): static {
        $this->elements[] = $content;

        $this->labelMap[] = $label ?? count(
            $this->labelMap
        );
        $this->validators[] = $validator;

        return $this;
    }
}