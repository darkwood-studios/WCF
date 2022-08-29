/**
 * Handles the mark as read button for single moderation queue entries.
 *
 * @author  Marcel Werk
 * @copyright  2001-2022 WoltLab GmbH
 * @license  GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @module  WoltLabSuite/Core/Ui/Moderation/MarkAsRead
 * @since 6.0
 */
define(["require", "exports", "../../Ajax"], function (require, exports, Ajax_1) {
    "use strict";
    Object.defineProperty(exports, "__esModule", { value: true });
    exports.setup = void 0;
    const unreadEntries = new WeakSet();
    async function markAsRead(entry) {
        var _a;
        const queueId = parseInt(entry.dataset.queueId, 10);
        await (0, Ajax_1.dboAction)("markAsRead", "wcf\\data\\moderation\\queue\\ModerationQueueAction").objectIds([queueId]).dispatch();
        entry.classList.remove("new");
        (_a = entry.querySelector(".columnAvatar p")) === null || _a === void 0 ? void 0 : _a.removeAttribute("title");
    }
    function setup() {
        document.querySelectorAll(".moderationQueueEntryList .new .columnAvatar").forEach((el) => {
            if (!unreadEntries.has(el)) {
                unreadEntries.add(el);
                el.addEventListener("dblclick", (event) => {
                    event.preventDefault();
                    const entry = el.closest(".moderationQueueEntry");
                    if (!entry.classList.contains("new")) {
                        return;
                    }
                    void markAsRead(entry);
                }, { once: true });
            }
        });
    }
    exports.setup = setup;
});