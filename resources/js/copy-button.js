/**
 * After a copy button copied its text: "Copied" for a moment, then the button's own label again.
 * The label is kept from the first click, so quick clicks cannot leave the button on "Copied".
 *
 * @param {HTMLElement} button
 * @param {WeakMap<HTMLElement, number>} timers One timer per button.
 */
export function showCopied(button, timers, delay = 1500) {
    button.dataset.copyLabel ??= button.textContent;
    button.textContent = button.dataset.copied || 'Copied';
    clearTimeout(timers.get(button));
    timers.set(
        button,
        setTimeout(() => {
            button.textContent = button.dataset.copyLabel;
            delete button.dataset.copyLabel;
            timers.delete(button);
        }, delay),
    );
}
