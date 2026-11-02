<?php

namespace App\Support\Sections;

use App\Models\Section;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

/**
 * Makes a subject's sections exactly the given list: adds new ones, renames existing ones (by id, so the students
 * enrolled in them and the groups in them follow the new name without any other change) and removes the ones left out,
 * but only when nothing uses them.
 */
class SectionManager
{
    /**
     * @param  list<array{id?: int|string|null, name: string}>  $rows
     *
     * @throws SectionException
     */
    public static function sync(Subject $subject, array $rows): void
    {
        DB::transaction(function () use ($subject, $rows) {
            $existing = $subject->sections()->withCount(['enrolments', 'groups'])->get()->keyBy('id');
            $kept = [];

            foreach ($rows as $row) {
                $id = isset($row['id']) && $existing->has((int) $row['id']) ? (int) $row['id'] : null;

                if ($id !== null) {
                    $kept[$id] = Enrolments::clean($row['name']);
                }
            }

            foreach ($existing as $id => $section) {
                if (array_key_exists($id, $kept)) {
                    continue;
                }

                if ($section->enrolments_count > 0 || $section->groups_count > 0) {
                    throw new SectionException(
                        "Section {$section->name} cannot be removed: ".self::using($section).'. Move or remove them first, or keep the section.'
                    );
                }

                $section->delete();
            }

            // Two steps, so that swapping two names (1A and 1B) never meets the unique index half way.
            foreach ($kept as $id => $name) {
                if ($existing[$id]->name !== $name) {
                    $existing[$id]->forceFill(['name' => '~'.$id])->save();
                }
            }

            foreach ($kept as $id => $name) {
                if ($existing[$id]->name !== $name) {
                    $existing[$id]->forceFill(['name' => $name])->save();
                }
            }

            foreach ($rows as $row) {
                $id = isset($row['id']) ? (int) $row['id'] : null;

                if ($id === null || ! $existing->has($id)) {
                    Section::create(['subject_id' => $subject->getKey(), 'name' => Enrolments::clean($row['name'])]);
                }
            }
        });
    }

    protected static function using(Section $section): string
    {
        $parts = [];

        if ($section->enrolments_count > 0) {
            $parts[] = $section->enrolments_count.' '.str('student')->plural($section->enrolments_count).' enrolled';
        }

        if ($section->groups_count > 0) {
            $parts[] = $section->groups_count.' '.str('group')->plural($section->groups_count).' in it';
        }

        return implode(' and ', $parts);
    }
}
