import { reactive } from 'vue';

/**
 * Wraps a form-submitting function so it only fires after the user confirms
 * in a ConfirmActionModal, instead of submitting immediately on click.
 *
 * Bind the two fields, never the object:
 *
 *     :open="confirm.open" :processing="confirm.processing"
 *
 * `confirm` is a reactive object, so `:open="confirm"` is permanently truthy —
 * the modal renders open on page load and `cancel()` cannot shut it, because
 * what it sets is not what the template is reading. It looks exactly like a
 * close button that does nothing, and the roles form shipped that way.
 *
 * `processing` belongs to this object too, not to the Inertia form: it is what
 * keeps the modal open and its buttons disabled for the length of the request,
 * and `cancel()` reads it to refuse a dismissal mid-flight.
 */
export default function useConfirmSubmit(submit) {
    const confirm = reactive({ open: false, processing: false });

    const request = () => { confirm.open = true; };
    const cancel = () => { if (!confirm.processing) { confirm.open = false; } };
    const proceed = () => {
        confirm.processing = true;
        submit({
            onFinish: () => {
                confirm.processing = false;
                confirm.open = false;
            },
        });
    };

    return { confirm, request, cancel, proceed };
}
