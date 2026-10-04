import { Head, Link } from "@inertiajs/react";
import { ListCharts, type ListChart } from "@/components/charts/list-charts";
import { ClipboardList, Plus, SearchX } from "lucide-react";
import { ButtonLink } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ExaminationSectionTabs } from "@/components/examinations/examination-section-tabs";
import { FilterBar, SearchField } from "@/components/ui/filter-bar";
import { FormField, SelectInput } from "@/components/ui/form-field";
import { PageHeader } from "@/components/ui/page-header";
import { Pagination } from "@/components/ui/pagination";
import { StatusBadge, type StatusTone } from "@/components/ui/status-badge";
import { Table, TableBody, TableHead, Td, Th, Tr } from "@/components/ui/table";
import { examinationRoutes } from "@/lib/examination-routes";
import { terms } from "@/lib/terminology";
import { useQueryFilters } from "@/lib/use-query-filters";
import type { Paginated } from "@/types";

interface Exam {
    id: number;
    title: string;
    kind: string;
    lifecycle: { value: string; label: string; tone: StatusTone };
    class_subject: { subject: { name: string }; class_batch: { name: string } };
    duration_minutes: number | null;
}

type ExaminationFilters = {
    search: string;
    status: string;
    kind: string;
    offering: string;
};

interface ExaminationIndexProps {
    examinations: Paginated<Exam>;
    charts: ListChart[];
    filters: ExaminationFilters;
    /** All of the instructor's quizzes and examinations, before filtering. */
    total: number;
    statusOptions: Array<{ value: string; label: string }>;
    kindOptions: Array<{ value: string; label: string }>;
    /** The class subjects the instructor teaches. */
    offeringOptions: Array<{ id: number; name: string }>;
}

const kindLabels: Record<string, string> = {
    examination: "Examination",
    quiz: "Quiz",
};

export default function ExaminationIndex({
    examinations,
    charts,
    filters,
    total,
    statusOptions,
    kindOptions,
    offeringOptions,
}: ExaminationIndexProps) {
    const { values, update, reset, isFiltered } = useQueryFilters(
        examinationRoutes.index(),
        filters,
    );
    const createAction = (
        <ButtonLink
            href={examinationRoutes.create()}
            variant="primary"
            icon={<Plus className="size-4" aria-hidden="true" />}
        >
            Create Examination
        </ButtonLink>
    );

    return (
        <>
            <Head title="Examinations" />
            <PageHeader
                title="Quizzes & Examinations"
                description="Build, review, and monitor assessments for your assigned subjects."
                actions={total > 0 && createAction}
            />

            <ExaminationSectionTabs current="examinations" />

            <ListCharts charts={charts} />

            <section
                aria-label="Examinations"
                className="rounded-xl border border-line-box bg-surface"
            >
                {total === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title="No examinations yet"
                        description="Create a draft quiz or examination for a subject you teach, then add questions and publish it."
                        action={createAction}
                    />
                ) : (
                    <>
                        <FilterBar onReset={reset} canReset={isFiltered}>
                            <SearchField
                                label="Search by title"
                                placeholder="e.g. Quiz 1"
                                value={values.search}
                                onChange={(value) =>
                                    update("search", value, { debounce: true })
                                }
                            />
                            <FormField label="Status" className="sm:w-52">
                                <SelectInput
                                    value={values.status}
                                    onChange={(event) =>
                                        update("status", event.target.value)
                                    }
                                >
                                    <option value="">Any status</option>
                                    {statusOptions.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            <FormField label="Kind" className="sm:w-40">
                                <SelectInput
                                    value={values.kind}
                                    onChange={(event) =>
                                        update("kind", event.target.value)
                                    }
                                >
                                    <option value="">
                                        Quizzes and examinations
                                    </option>
                                    {kindOptions.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                            <FormField
                                label={`${terms.classBatch.singular} / Subject`}
                                className="sm:w-64"
                            >
                                <SelectInput
                                    value={values.offering}
                                    onChange={(event) =>
                                        update("offering", event.target.value)
                                    }
                                >
                                    <option value="">All your subjects</option>
                                    {offeringOptions.map((option) => (
                                        <option
                                            key={option.id}
                                            value={String(option.id)}
                                        >
                                            {option.name}
                                        </option>
                                    ))}
                                </SelectInput>
                            </FormField>
                        </FilterBar>

                        {examinations.data.length === 0 ? (
                            <EmptyState
                                icon={SearchX}
                                headingLevel="h3"
                                title="No examinations match these filters"
                                description="Try a different title or status, or reset the filters to see every quiz and examination."
                            />
                        ) : (
                            <Table caption="Quizzes and examinations">
                                <TableHead>
                                    <Th>Title</Th>
                                    <Th>{`${terms.classBatch.singular} / Subject`}</Th>
                                    <Th align="right">Time limit</Th>
                                    <Th>Status</Th>
                                </TableHead>
                                <TableBody>
                                    {examinations.data.map((exam) => (
                                        <Tr key={exam.id}>
                                            <Td>
                                                <Link
                                                    className="font-medium text-primary-700 underline"
                                                    href={examinationRoutes.show(
                                                        exam.id,
                                                    )}
                                                >
                                                    {exam.title}
                                                </Link>
                                                <p className="text-xs text-ink-muted">
                                                    {kindLabels[exam.kind] ??
                                                        exam.kind}
                                                </p>
                                            </Td>
                                            <Td>
                                                {
                                                    exam.class_subject
                                                        .class_batch.name
                                                }
                                                <span aria-hidden="true">
                                                    {" "}
                                                    ·{" "}
                                                </span>
                                                <span className="sr-only">
                                                    ,{" "}
                                                </span>
                                                {
                                                    exam.class_subject.subject
                                                        .name
                                                }
                                            </Td>
                                            <Td numeric align="right">
                                                {exam.duration_minutes
                                                    ? `${exam.duration_minutes} min`
                                                    : "Not set"}
                                            </Td>
                                            <Td>
                                                <StatusBadge
                                                    tone={exam.lifecycle.tone}
                                                >
                                                    {exam.lifecycle.label}
                                                </StatusBadge>
                                            </Td>
                                        </Tr>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        <Pagination
                            page={examinations}
                            noun={{ one: "assessment", other: "assessments" }}
                        />
                    </>
                )}
            </section>
        </>
    );
}
