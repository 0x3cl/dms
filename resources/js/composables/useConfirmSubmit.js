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
 *
 * **`request()` refuses to open the dialog over an invalid form.** Bind it to
 * `@submit.prevent="request"` and it checks the form the event came from
 * first. Asking "create this type?" about a form that cannot be created is
 * the worst of both: the person answers yes, the server refuses, and the
 * errors appear *behind* a dialog they then have to dismiss to read. The
 * browser's own `reportValidity()` does the checking and the messaging, so
 * the fields need nothing but `required` — and a form with no constraints is
 * unaffected, because `reportValidity()` returns true for one.
 */
export default function useConfirmSubmit(submit) {
    const confirm = reactive({ open: false, processing: false });

    const request = (event) => {
        const form = event?.target;

        if (typeof form?.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }

        confirm.open = true;
    };
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
