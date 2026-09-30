<?php

namespace App\Services\QuestionBank;

use App\Enums\QuestionType;
use App\Http\Requests\QuestionBank\QuestionRequest;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Imports many questions into one subject from a UTF-8 CSV file.
 *
 * All or nothing: every row is checked first, with the rules and messages of
 * the question form (QuestionRequest) plus the CSV-specific ones below. If
 * any row has a problem nothing is created; otherwise every question is
 * created through QuestionBankService::create() (which checks the rules
 * again and audits each question) in one database transaction.
 *
 * Problems are reported as validation errors: problems with the whole file
 * under "file", problems with a cell under "rows.{row}.{column}". Row numbers
 * are spreadsheet rows: the header is row 1. Messages never repeat question
 * text, choices, answers, or explanations, and nothing here is logged.
 *
 * The caller authorizes the actor and checks that they teach the subject
 * (QuestionImportRequest).
 */
final class QuestionImportService
{
    public const MAX_ROWS = 500;

    public const MAX_FILE_KILOBYTES = 1024;

    /** Problems listed at most; a file with more gets a note to fix these first. */
    public const MAX_LISTED_PROBLEMS = 100;

    public const DEFAULT_POINTS = '1';

    public const CHOICE_COLUMNS = ['choice_a', 'choice_b', 'choice_c', 'choice_d', 'choice_e', 'choice_f'];

    public const COLUMNS = ['type', 'question', ...self::CHOICE_COLUMNS, 'correct', 'points', 'topic', 'explanation'];

    public const REQUIRED_COLUMNS = ['type', 'question'];

    /** Column key of problems that concern the whole row. */
    public const ROW = 'row';

    public const NOT_CSV_MESSAGE = 'The file is not a UTF-8 CSV file. In your spreadsheet, use Save As and choose "CSV UTF-8 (Comma delimited)".';

    public const EMPTY_FILE_MESSAGE = 'The file is empty. Download the template, add your questions below the header row, and upload it again.';

    public const NO_ROWS_MESSAGE = 'The file has a header row but no questions. Add one question per row below the header row.';

    public const TYPE_MESSAGE = 'Use multiple_choice, true_false, or essay (or MC, TF, Essay).';

    private const TYPE_ALIASES = [
        'multiple_choice' => QuestionType::MultipleChoice,
        'mc' => QuestionType::MultipleChoice,
        'true_false' => QuestionType::TrueFalse,
        'tf' => QuestionType::TrueFalse,
        'essay' => QuestionType::Essay,
    ];

    private const TEMPLATE_ROWS = [
        [
            'type' => 'multiple_choice',
            'question' => 'Which value is the sum of 2 and 3?',
            'choice_a' => '4',
            'choice_b' => '5',
            'choice_c' => '6',
            'choice_d' => '7',
            'correct' => 'B',
            'points' => '1',
            'topic' => 'Topic 1',
            'explanation' => '2 + 3 = 5.',
        ],
        [
            'type' => 'true_false',
            'question' => 'A week has seven days.',
            'correct' => 'True',
            'points' => '1',
            'topic' => 'Topic 1',
            'explanation' => 'Monday to Sunday are seven days.',
        ],
        [
            'type' => 'essay',
            'question' => 'Explain, in your own words, why regular review helps learning.',
            'points' => '5',
            'topic' => 'Topic 2',
            'explanation' => 'Look for a clear explanation with at least one example.',
        ],
    ];

    public function __construct(private readonly QuestionBankService $questions) {}

    /**
     * Creates every question of the file in the subject.
     *
     * @return int the number of questions created
     *
     * @throws ValidationException when the file or any row has a problem (nothing is created)
     */
    public function import(Subject $subject, string $contents, User $actor): int
    {
        $rows = $this->validatedRows($contents, $actor);

        return DB::transaction(function () use ($subject, $rows, $actor): int {
            foreach ($rows as $rowNumber => $data) {
                try {
                    $this->questions->create($subject, $data, $actor);
                } catch (ValidationException $exception) {
                    // The service checks the rules again; report its problems by row too.
                    $problems = [];
                    foreach ($exception->errors() as $key => $messages) {
                        $message = (string) ($messages[0] ?? '');
                        $problems['rows.'.$rowNumber.'.'.self::columnFor($key, $message)] = $message;
                    }

                    throw ValidationException::withMessages($problems);
                }
            }

            return count($rows);
        });
    }

    /**
     * The template: the header row and one example of each question type,
     * with a byte order mark so spreadsheets open it as UTF-8.
     */
    public function template(): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\u{FEFF}");
        fputcsv($stream, self::COLUMNS, ',', '"', '', "\r\n");
        foreach (self::TEMPLATE_ROWS as $row) {
            fputcsv($stream, array_map(fn (string $column): string => $row[$column] ?? '', self::COLUMNS), ',', '"', '', "\r\n");
        }
        rewind($stream);
        $template = (string) stream_get_contents($stream);
        fclose($stream);

        return $template;
    }

    /**
     * @return array<int, QuestionData> keyed by row number
     *
     * @throws ValidationException
     */
    private function validatedRows(string $contents, User $actor): array
    {
        $records = $this->records($contents);
        $columnIndexes = $this->columnIndexes($records[1]);

        $dataRows = array_filter(
            array_slice($records, 1, preserve_keys: true),
            fn (array $fields): bool => implode('', $fields) !== '',
        );

        if ($dataRows === []) {
            throw ValidationException::withMessages(['file' => self::NO_ROWS_MESSAGE]);
        }

        if (count($dataRows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has '.number_format(count($dataRows)).' questions. Import at most '.self::MAX_ROWS.' at a time: split them into smaller files.']);
        }

        // The question form's own rules and messages. The subject is checked once for the whole file.
        $form = new QuestionRequest;
        $form->setUserResolver(fn (): User => $actor);
        $rules = Arr::except($form->rules(), ['subject_id']);
        $messages = $form->messages();

        $rows = [];
        $problems = [];
        $problemCount = 0;

        foreach ($dataRows as $rowNumber => $fields) {
            [$data, $rowProblems] = $this->validateRow($this->rowValues($fields, $columnIndexes), $fields, $columnIndexes, $rules, $messages);

            foreach ($rowProblems as $column => $message) {
                if (++$problemCount <= self::MAX_LISTED_PROBLEMS) {
                    $problems["rows.{$rowNumber}.{$column}"] = $message;
                }
            }

            if ($data !== null) {
                $rows[$rowNumber] = $data;
            }
        }

        if ($problemCount > self::MAX_LISTED_PROBLEMS) {
            $problems['file'] = 'The file has '.number_format($problemCount).' problems. The first '.self::MAX_LISTED_PROBLEMS.' are listed; fix them and upload the file again to see the rest.';
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return $rows;
    }

    /**
     * The CSV records keyed by row number (1 is the header), each field with
     * line breaks as "\n" and surrounding whitespace removed.
     *
     * @return array<int, list<string>>
     *
     * @throws ValidationException
     */
    private function records(string $contents): array
    {
        if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
            throw ValidationException::withMessages(['file' => self::NOT_CSV_MESSAGE]);
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => self::NOT_CSV_MESSAGE]);
        }

        if (trim($contents) === '') {
            throw ValidationException::withMessages(['file' => self::EMPTY_FILE_MESSAGE]);
        }

        // Line breaks inside quoted fields are normalized the same way as question text.
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, str_replace(["\r\n", "\r"], "\n", $contents));
        rewind($stream);

        $records = [];
        $rowNumber = 0;
        while (($fields = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $records[++$rowNumber] = array_map(fn (?string $field): string => QuestionContent::normalizeText((string) $field), $fields);
        }
        fclose($stream);

        return $records;
    }

    /**
     * Index of each known column in the header row. Names ignore case,
     * surrounding spaces, and spaces or hyphens instead of underscores
     * ("Choice A" is choice_a). Columns without a name are ignored as long as
     * they are empty (see validateRow()).
     *
     * @param  list<string>  $header
     * @return array<string, int>
     *
     * @throws ValidationException
     */
    private function columnIndexes(array $header): array
    {
        if (implode('', $header) === '') {
            throw ValidationException::withMessages(['file' => 'The first row must be the header row with the column names (type, question, and so on). Download the template to see the format.']);
        }

        if (count($header) === 1 && str_contains($header[0], ';')) {
            throw ValidationException::withMessages(['file' => 'Separate the columns with commas, not semicolons. In your spreadsheet, use Save As and choose "CSV UTF-8 (Comma delimited)".']);
        }

        $indexes = [];
        $unknown = [];
        $repeated = [];

        foreach ($header as $index => $name) {
            $column = (string) preg_replace('/[\s-]+/u', '_', mb_strtolower($name));
            if ($column === '') {
                continue;
            }

            if (! in_array($column, self::COLUMNS, true)) {
                $unknown[] = '"'.mb_strimwidth($name, 0, 40, '…').'"';
            } elseif (array_key_exists($column, $indexes)) {
                $repeated[$column] = '"'.$column.'"';
            } else {
                $indexes[$column] = $index;
            }
        }

        $problems = [];

        if ($unknown !== []) {
            $problems[] = (count($unknown) === 1 ? 'Remove the column '.$unknown[0] : 'Remove the columns '.implode(', ', $unknown))
                .'. The allowed columns are type, question, choice_a to choice_f, correct, points, topic, and explanation.';
        }

        if ($repeated !== []) {
            $problems[] = (count($repeated) === 1 ? 'The column '.implode('', $repeated).' appears' : 'The columns '.implode(', ', $repeated).' appear')
                .' more than once in the header row. Keep one of each.';
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($indexes)));
        if ($missing !== []) {
            $problems[] = (count($missing) === 1 ? "Add the column \"{$missing[0]}\"" : 'Add the columns "'.implode('" and "', $missing).'"').' to the header row.';
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(['file' => implode(' ', $problems)]);
        }

        return $indexes;
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, int>  $columnIndexes
     * @return array<string, string> every column, empty when absent
     */
    private function rowValues(array $fields, array $columnIndexes): array
    {
        $values = [];
        foreach (self::COLUMNS as $column) {
            $values[$column] = isset($columnIndexes[$column]) ? ($fields[$columnIndexes[$column]] ?? '') : '';
        }

        return $values;
    }

    /**
     * Checks one row. The CSV-specific checks come first; the first problem
     * found for a column is the one reported.
     *
     * @param  array<string, string>  $values
     * @param  list<string>  $fields
     * @param  array<string, int>  $columnIndexes
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array{0: QuestionData|null, 1: array<string, string>} the question, or null with the problems by column
     */
    private function validateRow(array $values, array $fields, array $columnIndexes, array $rules, array $messages): array
    {
        $problems = [];
        $report = function (string $column, string $message) use (&$problems): void {
            $problems[$column] ??= $message;
        };

        foreach ($fields as $index => $field) {
            if ($field !== '' && ! in_array($index, $columnIndexes, true)) {
                $report(self::ROW, 'This row has a value in a column without a name in the header row. Check for a comma in a value that is not in quotes, or delete the extra column.');
                break;
            }
        }

        $type = self::TYPE_ALIASES[mb_strtolower($values['type'])] ?? null;
        if ($type === null) {
            $report('type', $values['type'] === '' ? 'Enter the question type. '.self::TYPE_MESSAGE : self::TYPE_MESSAGE);
        }

        $choices = [];
        $answer = null;

        match ($type) {
            QuestionType::MultipleChoice => $choices = $this->multipleChoices($values, $report),
            QuestionType::TrueFalse => $answer = $this->trueFalseAnswer($values, $report),
            QuestionType::Essay => $this->checkEssay($values, $report),
            null => null,
        };

        $payload = [
            'topic' => $values['topic'] === '' ? null : $values['topic'],
            'points' => $values['points'] === '' ? self::DEFAULT_POINTS : $values['points'],
            'explanation' => $values['explanation'] === '' ? null : $values['explanation'],
            'type' => $type->value ?? $values['type'],
            'prompt' => $values['question'],
            'choices' => $choices,
            'correct_answer' => mb_strtolower($values['correct']),
        ];

        foreach (Validator::make($payload, $rules, $messages)->errors()->messages() as $key => $keyMessages) {
            $message = (string) ($keyMessages[0] ?? '');
            $report(self::columnFor($key, $message), $message);
        }

        if ($problems !== []) {
            return [null, $problems];
        }

        $content = match ($type) {
            QuestionType::MultipleChoice => QuestionContent::multipleChoice($values['question'], $choices),
            QuestionType::TrueFalse => QuestionContent::trueFalse($values['question'], $answer === true),
            QuestionType::Essay => QuestionContent::essay($values['question']),
        };

        return [new QuestionData($payload['topic'], $payload['points'], $payload['explanation'], $content), []];
    }

    /**
     * The choices up to the last filled one (by position, so an empty choice
     * between filled ones stays in place and is reported), with the one named
     * by "correct" marked.
     *
     * @param  array<string, string>  $values
     * @param  callable(string, string): void  $report
     * @return list<array{text: string, is_correct: bool}>
     */
    private function multipleChoices(array $values, callable $report): array
    {
        $texts = array_map(fn (string $column): string => $values[$column], self::CHOICE_COLUMNS);
        $filled = array_keys(array_filter($texts, fn (string $text): bool => $text !== ''));
        $lastIndex = $filled === [] ? -1 : max($filled);
        $range = 'A–'.self::letter(max($lastIndex, QuestionType::MIN_CHOICES - 1));

        for ($index = 0; $index <= max($lastIndex, QuestionType::MIN_CHOICES - 1); $index++) {
            if ($texts[$index] === '') {
                $letter = self::letter($index);
                $report(self::CHOICE_COLUMNS[$index], $index < $lastIndex
                    ? "Fill choice {$letter}, or move the later choices up: choices are filled from A without gaps."
                    : 'Fill at least choices A and B. '.QuestionBankService::CHOICE_COUNT_MESSAGE);
                break;
            }
        }

        $duplicates = [];
        foreach ($filled as $index) {
            $duplicates[mb_strtolower($texts[$index])][] = $index;
        }
        foreach ($duplicates as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $report(self::CHOICE_COLUMNS[$index], QuestionBankService::DUPLICATE_CHOICE_MESSAGE);
                }
            }
        }

        $correct = mb_strtoupper($values['correct']);
        $correctIndex = strlen($correct) === 1 && $correct >= 'A' && $correct <= 'F' ? ord($correct) - ord('A') : null;

        if ($correct === '') {
            $report('correct', "Enter the letter of the correct choice ({$range}).");
        } elseif ($correctIndex === null) {
            $report('correct', "Use the letter of the correct choice ({$range}), for example B.");
        } elseif ($texts[$correctIndex] === '') {
            $report('correct', "Use a letter of a filled choice ({$range}).");
        }

        return array_map(
            fn (int $index): array => ['text' => $texts[$index], 'is_correct' => $index === $correctIndex],
            $lastIndex < 0 ? [] : range(0, $lastIndex),
        );
    }

    /**
     * @param  array<string, string>  $values
     * @param  callable(string, string): void  $report
     */
    private function trueFalseAnswer(array $values, callable $report): ?bool
    {
        $this->checkNoChoices($values, $report, 'Leave the choice columns empty for true / false questions: their choices are always True and False.');

        return match (mb_strtolower($values['correct'])) {
            'true' => true,
            'false' => false,
            default => $report('correct', 'Enter True or False: whether the statement is true.'),
        };
    }

    /**
     * @param  array<string, string>  $values
     * @param  callable(string, string): void  $report
     */
    private function checkEssay(array $values, callable $report): void
    {
        $this->checkNoChoices($values, $report, 'Leave the choice columns empty for essay questions: they have no answer choices.');

        if ($values['correct'] !== '') {
            $report('correct', 'Leave correct empty for essay questions: an instructor grades the answers. Put grading guidance in explanation.');
        }
    }

    /**
     * @param  array<string, string>  $values
     * @param  callable(string, string): void  $report
     */
    private function checkNoChoices(array $values, callable $report, string $message): void
    {
        foreach (self::CHOICE_COLUMNS as $column) {
            if ($values[$column] !== '') {
                $report($column, $message);

                return;
            }
        }
    }

    /**
     * The CSV column of a question form (or QuestionBankService) error key.
     */
    private static function columnFor(string $key, string $message): string
    {
        if (preg_match('/^choices\.(\d+)\.text$/', $key, $matches) === 1) {
            return self::CHOICE_COLUMNS[(int) $matches[1]] ?? 'correct';
        }

        return match (true) {
            $key === 'prompt' => 'question',
            $key === 'choices' => $message === QuestionBankService::CORRECT_CHOICE_MESSAGE ? 'correct' : 'choice_a',
            $key === 'correct_answer', str_starts_with($key, 'choices.') => 'correct',
            in_array($key, ['type', 'points', 'topic', 'explanation'], true) => $key,
            default => self::ROW,
        };
    }

    private static function letter(int $index): string
    {
        return chr(ord('A') + $index);
    }
}
