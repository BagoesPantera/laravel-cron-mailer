<?php

namespace Pantera\CronMailer\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use ReflectionClass;
use ReflectionProperty;

class MailableSerializer
{
    /**
     * Capture every public property of the given mailable.
     *
     * Eloquent models are stored as a class/key reference so the worker can
     * reload them with fresh data right before sending.
     *
     * @return array<string, array<string, mixed>>
     */
    public function serialize(Mailable $mailable): array
    {
        $properties = [];

        foreach ((new ReflectionClass($mailable))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if (! $property->isInitialized($mailable)) {
                continue;
            }

            $properties[$property->getName()] = $this->normalize($property->getValue($mailable));
        }

        return $properties;
    }

    /**
     * Normalize a single property value into a JSON friendly structure.
     *
     * @return array<string, mixed>
     */
    protected function normalize(mixed $value): array
    {
        if ($value instanceof Model) {
            return [
                '__is_model' => true,
                'class' => get_class($value),
                'id' => $value->getKey(),
            ];
        }

        return [
            '__is_model' => false,
            'value' => $value,
        ];
    }
}
