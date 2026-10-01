<?php

namespace App\Http\Requests\Fitness;

use App\Enums\FitnessUnit;
use App\Models\FitnessTest;
use App\Models\FitnessTestEvent;
use App\Services\Fitness\FitnessValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Raw fitness results for some candidates of a test:
 * entries[candidate id][test event id] = "42" or "12:30"; an empty value
 * removes a result. Values are parsed in the event's unit; each invalid
 * value is reported on its own field (entries.{candidate}.{event}).
 * Authorization is enforced by the `can:fitness.manage` route middleware;
 * class membership is checked by FitnessTestService.
 */
class RecordFitnessResultsRequest extends FormRequest
{
    /** @var array<int, array<int, float|null>> */
    private array $parsed = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*' => ['array', 'max:30'],
            'entries.*.*' => ['nullable', 'string', 'max:12'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var FitnessTest $test */
            $test = $this->route('fitnessTest');
            /** @var Collection<int, FitnessTestEvent> $events */
            $events = $test->events()->get()->keyBy('id');

            foreach ((array) $this->input('entries') as $candidateId => $values) {
                if (filter_var($candidateId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    $validator->errors()->add('entries', 'The results could not be read. Reload the page and try again.');

                    return;
                }

                foreach ((array) $values as $eventId => $input) {
                    $event = filter_var($eventId, FILTER_VALIDATE_INT) === false ? null : $events->get((int) $eventId);
                    if ($event === null) {
                        $validator->errors()->add('entries', 'One of the events is not part of this test. Reload the page and try again.');

                        return;
                    }

                    $text = trim((string) $input);
                    if ($text === '') {
                        $this->parsed[(int) $candidateId][$event->id] = null;

                        continue;
                    }

                    $value = FitnessValue::parse($text, $event->unit);
                    if ($value === null) {
                        $validator->errors()->add(
                            "entries.{$candidateId}.{$event->id}",
                            $event->unit === FitnessUnit::Time
                                ? "Enter {$event->name} as minutes:seconds, such as 12:30."
                                : "Enter {$event->name} as a whole number from 0 to 1,000.",
                        );

                        continue;
                    }

                    $this->parsed[(int) $candidateId][$event->id] = $value;
                }
            }
        }];
    }

    /**
     * @return array<int, array<int, float|null>>
     */
    public function results(): array
    {
        return $this->parsed;
    }
}
