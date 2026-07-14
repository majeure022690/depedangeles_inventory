<script setup lang="ts">
import { Info } from '@lucide/vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * Compact field-level guidance: a small info affordance that reveals the
 * full instruction text on hover/focus for sighted users, paired with an
 * always-present visually-hidden description wired to the field via
 * `aria-describedby` so screen reader users hear it as soon as the field
 * receives focus, independent of whether the tooltip itself is open.
 *
 * Usage:
 *   <Label for="property_no">Property number</Label>
 *   <FieldHint for="property_no" label="Property number" text="..." />
 *   <Input id="property_no" aria-describedby="property_no-hint" ... />
 */
const props = defineProps<{
    /** The id of the field this hint describes (used to build the hidden description's id). */
    for: string;
    /** Human-readable field label, used to build an accessible name for the trigger. */
    label: string;
    /** The guidance text, shown in the tooltip and exposed to assistive tech. */
    text: string;
}>();
</script>

<template>
    <span class="inline-flex items-center">
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        :aria-label="`Help: ${props.label}`"
                        class="inline-flex size-4 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1"
                    >
                        <Info class="size-3.5" aria-hidden="true" />
                    </button>
                </TooltipTrigger>
                <TooltipContent class="max-w-xs">
                    {{ props.text }}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
        <span :id="`${props.for}-hint`" class="sr-only">{{ props.text }}</span>
    </span>
</template>
