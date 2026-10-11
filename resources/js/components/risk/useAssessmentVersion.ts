import { watch } from 'vue';

/** Keep optimistic versions current without replacing an unsaved assessment. */
export function useAssessmentVersion(
    currentVersion: () => number,
    form: {
        lock_version: number;
        processing: boolean;
        errors: Record<string, string>;
    },
): { saved: () => void; refreshed: () => void } {
    watch(currentVersion, (version) => {
        if (!form.processing && !form.errors.cycle) form.lock_version = version;
    });
    return {
        saved: () => {
            form.lock_version = currentVersion();
        },
        refreshed: () => {
            form.lock_version = currentVersion();
            delete form.errors.cycle;
        },
    };
}
