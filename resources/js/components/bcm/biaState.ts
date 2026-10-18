import { ref, watch } from 'vue';
import {
    impactAreas,
    type BiaImpact,
    type BiaRevision,
    type RecoveryRevision,
} from '@/types/bcm';

export function nullableInteger(
    value: number | string | null,
): number | null | string {
    if (value === '' || value === null) return null;
    if (typeof value === 'number') return value;
    return /^-?\d+$/.test(value) && Number.isSafeInteger(Number(value))
        ? Number(value)
        : value;
}
export function minuteLabel(value: number | null): string {
    return value === null ? 'Unbekannt' : `${value} Minuten`;
}
export function firstCriticalMinute(
    impacts: Pick<BiaImpact, 'level' | 'minutes'>[],
): number | null {
    const minutes = impacts
        .filter((impact) => impact.level === 4)
        .map((impact) => impact.minutes);
    return minutes.length ? Math.min(...minutes) : null;
}
export function conflictLabel(code: string): string {
    return (
        (
            {
                dependency_target_unknown: 'Ziel der Abhängigkeit unbekannt',
                dependency_target_stale:
                    'Ziel der Abhängigkeit muss geprüft werden',
                dependency_rto_exceeds_process:
                    'Abhängigkeits-RTO überschreitet Prozess-RTO',
                dependency_rpo_exceeds_process:
                    'Abhängigkeits-RPO überschreitet Prozess-RPO',
                mtpd_after_critical_impact:
                    'MTPD liegt nach der ersten kritischen Auswirkung',
                descending_impact: 'Auswirkungen nehmen im Zeitverlauf ab',
            } as Record<string, string>
        )[code] ?? code
    );
}
export function recoveryData(revision: RecoveryRevision) {
    return {
        lock_version: revision.lock_version,
        rto_minutes: revision.rto_minutes as number | string | null,
        rpo_minutes: revision.rpo_minutes as number | string | null,
        rpo_not_applicable: revision.rpo_not_applicable,
        rpo_reason: revision.rpo_reason ?? '',
        target_reason: revision.target_reason ?? '',
    };
}
export function biaData(revision: BiaRevision) {
    return {
        ...recoveryData(revision),
        owner_name: revision.owner_name ?? '',
        mtpd_minutes: revision.mtpd_minutes as number | string | null,
        impacts: Object.keys(impactAreas).flatMap((area) =>
            revision.minutes.map((minutes) => {
                const impact = revision.impacts.find(
                    (row) => row.area === area && row.minutes === minutes,
                );
                return {
                    area: area as BiaImpact['area'],
                    minutes,
                    level: impact?.level ?? null,
                    reason: impact?.reason ?? '',
                };
            }),
        ),
    };
}
export function useBcmFormState(
    form: { lock_version: number },
    currentVersion: () => number,
) {
    const stale = ref(false);
    watch(currentVersion, (version) => {
        if (version !== form.lock_version) stale.value = true;
    });
    function httpException(response: { status: number }): boolean | void {
        if (response.status === 409) {
            stale.value = true;
            return false;
        }
    }
    function refreshed(): void {
        form.lock_version = currentVersion();
        stale.value = false;
    }
    return { stale, httpException, refreshed };
}
