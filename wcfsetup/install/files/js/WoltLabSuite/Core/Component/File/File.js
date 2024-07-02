define(["require", "exports"], function (require, exports) {
    "use strict";
    Object.defineProperty(exports, "__esModule", { value: true });
    exports.removeUploadProgress = exports.trackUploadProgress = void 0;
    function trackUploadProgress(element, file) {
        const progress = document.createElement("progress");
        progress.classList.add("fileList__item__progress__bar");
        progress.max = 100;
        const readout = document.createElement("span");
        readout.classList.add("fileList__item__progress__readout");
        file.addEventListener("uploadProgress", (event) => {
            progress.value = event.detail;
            readout.textContent = `${event.detail}%`;
            if (progress.parentNode === null) {
                element.classList.add("fileProcessor__item--uploading");
                const wrapper = document.createElement("div");
                wrapper.classList.add("fileList__item__progress");
                wrapper.append(progress, readout);
                element.append(wrapper);
            }
        });
    }
    exports.trackUploadProgress = trackUploadProgress;
    function removeUploadProgress(element) {
        if (!element.classList.contains("fileProcessor__item--uploading")) {
            return;
        }
        element.classList.remove("fileProcessor__item--uploading");
        element.querySelector(".fileList__item__progress")?.remove();
    }
    exports.removeUploadProgress = removeUploadProgress;
});
