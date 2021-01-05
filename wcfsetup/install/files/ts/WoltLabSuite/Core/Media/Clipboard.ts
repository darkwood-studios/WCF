/**
 * Initializes modules required for media clipboard.
 *
 * @author  Matthias Schmidt
 * @copyright 2001-2021 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @module  WoltLabSuite/Core/Media/Clipboard
 */

import MediaManager from "./Manager/Base";
import MediaManagerEditor from "./Manager/Editor";
import * as Clipboard from "../Controller/Clipboard";
import * as UiNotification from "../Ui/Notification";
import * as UiDialog from "../Ui/Dialog";
import * as EventHandler from "../Event/Handler";
import * as Language from "../Language";
import * as Ajax from "../Ajax";
import { AjaxCallbackObject, AjaxCallbackSetup } from "../Ajax/Data";
import { DialogCallbackObject, DialogCallbackSetup } from "../Ui/Dialog/Data";

let _mediaManager: MediaManager;

class MediaClipboard implements AjaxCallbackObject, DialogCallbackObject {
  public _ajaxSetup(): ReturnType<AjaxCallbackSetup> {
    return {
      data: {
        className: "wcf\\data\\media\\MediaAction",
      },
    };
  }

  public _ajaxSuccess(data): void {
    switch (data.actionName) {
      case "getSetCategoryDialog":
        UiDialog.open(this, data.returnValues.template);

        break;

      case "setCategory":
        UiDialog.close(this);

        UiNotification.show();

        Clipboard.reload();

        break;
    }
  }

  public _dialogSetup(): ReturnType<DialogCallbackSetup> {
    return {
      id: "mediaSetCategoryDialog",
      options: {
        onSetup: (content) => {
          content.querySelector("button")!.addEventListener("click", (event) => {
            event.preventDefault();

            const category = content.querySelector('select[name="categoryID"]') as HTMLSelectElement;
            setCategory(~~category.value);

            const target = event.currentTarget as HTMLButtonElement;
            target.disabled = true;
          });
        },
        title: Language.get("wcf.media.setCategory"),
      },
      source: null,
    };
  }
}

const ajax = new MediaClipboard();

let clipboardObjectIds: number[] = [];

type ClipboardActionData = {
  data: {
    actionName: string;
    parameters: {
      objectIDs: number[];
    };
  };
  responseData: null;
};

/**
 * Handles successful clipboard actions.
 */
function clipboardAction(actionData: ClipboardActionData): void {
  const mediaIds = actionData.data.parameters.objectIDs;

  switch (actionData.data.actionName) {
    case "com.woltlab.wcf.media.delete":
      // only consider events if the action has been executed
      if (actionData.responseData !== null) {
        _mediaManager.clipboardDeleteMedia(mediaIds);
      }

      break;

    case "com.woltlab.wcf.media.insert": {
      const mediaManagerEditor = _mediaManager as MediaManagerEditor;
      mediaManagerEditor.clipboardInsertMedia(mediaIds);

      break;
    }

    case "com.woltlab.wcf.media.setCategory":
      clipboardObjectIds = mediaIds;

      Ajax.api(ajax, {
        actionName: "getSetCategoryDialog",
      });

      break;
  }
}

/**
 * Sets the category of the marked media files.
 */
function setCategory(categoryID: number) {
  Ajax.api(ajax, {
    actionName: "setCategory",
    objectIDs: clipboardObjectIds,
    parameters: {
      categoryID: categoryID,
    },
  });
}

export function init(pageClassName: string, hasMarkedItems: boolean, mediaManager: MediaManager): void {
  Clipboard.setup({
    hasMarkedItems: hasMarkedItems,
    pageClassName: pageClassName,
  });

  _mediaManager = mediaManager;

  EventHandler.add("com.woltlab.wcf.clipboard", "com.woltlab.wcf.media", (data) => clipboardAction(data));
}
