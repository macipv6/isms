import { ref, watch } from 'vue';
import type { Ref } from 'vue';

/** Keep optimistic versions current without replacing an unsaved assessment. */
export function useAssessmentVersion(
    currentVersion: () => number,
    form: {
        lock_version: number;
        processing: boolean;
        errors: Record<string, string>;
    },
): { conflicted: Ref<boolean>; saved: () => void; refreshed: () => void } {
    const conflicted = ref(Boolean(form.errors.cycle));
    watch(
        () => form.errors.cycle,
        (error) => {
            if (error) conflicted.value = true;
        },
        { flush: 'sync' },
    );
    watch(currentVersion, (version) => {
        if (!form.processing && !conflicted.value) form.lock_version = version;
    });
    return {
        conflicted,
        saved: () => {
            if (!conflicted.value) form.lock_version = currentVersion();
        },
        refreshed: () => {
            form.lock_version = currentVersion();
            delete form.errors.cycle;
            conflicted.value = false;
        },
    };
}
