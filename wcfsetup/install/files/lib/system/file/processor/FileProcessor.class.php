<?php

namespace wcf\system\file\processor;

use wcf\data\file\File;
use wcf\data\file\thumbnail\FileThumbnail;
use wcf\data\file\thumbnail\FileThumbnailEditor;
use wcf\data\file\thumbnail\FileThumbnailList;
use wcf\data\object\type\ObjectType;
use wcf\data\object\type\ObjectTypeCache;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\image\adapter\ImageAdapter;
use wcf\system\image\ImageHandler;
use wcf\system\SingletonFactory;
use wcf\system\WCF;
use wcf\util\FileUtil;
use wcf\util\JSON;
use wcf\util\StringUtil;

/**
 * @author Alexander Ebert
 * @copyright 2001-2024 WoltLab GmbH
 * @license GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @since 6.1
 */
final class FileProcessor extends SingletonFactory
{
    /**
     * @var array<string, ObjectType>
     */
    private array $objectTypes;

    #[\Override]
    public function init(): void
    {
        $this->objectTypes = ObjectTypeCache::getInstance()->getObjectTypes('com.woltlab.wcf.file');
    }

    public function getProcessorByName(string $objectType): ?IFileProcessor
    {
        return $this->getObjectType($objectType)?->getProcessor();
    }

    public function getProcessorById(?int $objectTypeID): ?IFileProcessor
    {
        if ($objectTypeID === null) {
            return null;
        }

        foreach ($this->objectTypes as $objectType) {
            if ($objectType->objectTypeID === $objectTypeID) {
                return $objectType->getProcessor();
            }
        }

        return null;
    }

    public function getObjectType(string $objectType): ?ObjectType
    {
        return $this->objectTypes[$objectType] ?? null;
    }

    public function getHtmlElement(IFileProcessor $fileProcessor, array $context): string
    {
        $allowedFileExtensions = $fileProcessor->getAllowedFileExtensions($context);
        if (\in_array('*', $allowedFileExtensions)) {
            $allowedFileExtensions = '';
        } else {
            $allowedFileExtensions = \implode(
                ',',
                \array_map(
                    static fn (string $fileExtension) => ".{$fileExtension}",
                    $allowedFileExtensions
                )
            );
        }

        return \sprintf(
            <<<'HTML'
                <woltlab-core-file-upload
                    data-object-type="%s"
                    data-context="%s"
                    data-file-extensions="%s"
                    data-resize-configuration="%s"
                ></woltlab-core-file-upload>
                HTML,
            StringUtil::encodeHTML($fileProcessor->getObjectTypeName()),
            StringUtil::encodeHTML(JSON::encode($context)),
            StringUtil::encodeHTML($allowedFileExtensions),
            StringUtil::encodeHTML(JSON::encode($fileProcessor->getResizeConfiguration())),
        );
    }

    public function generateThumbnails(File $file): void
    {
        if (!$file->isImage()) {
            return;
        }

        $processor = $file->getProcessor();
        if ($processor === null) {
            return;
        }

        $formats = $processor->getThumbnailFormats();
        if ($formats === []) {
            return;
        }

        $thumbnailList = new FileThumbnailList();
        $thumbnailList->getConditionBuilder()->add("fileID = ?", [$file->fileID]);
        $thumbnailList->readObjects();

        $existingThumbnails = [];
        foreach ($thumbnailList as $thumbnail) {
            \assert($thumbnail instanceof FileThumbnail);
            $existingThumbnails[$thumbnail->identifier] = $thumbnail;
        }

        $imageAdapter = null;
        foreach ($formats as $format) {
            if (isset($existingThumbnails[$format->identifier])) {
                continue;
            }

            // Check if we the source image is larger than the dimensions of the
            // requested thumbnails.
            if ($format->width > $file->width && $format->height > $file->height) {
                continue;
            }

            if ($imageAdapter === null) {
                $imageAdapter = ImageHandler::getInstance()->getAdapter();
                $imageAdapter->loadFile($file->getPathname());
            }

            \assert($imageAdapter instanceof ImageAdapter);
            $image = $imageAdapter->createThumbnail($format->width, $format->height, $format->retainDimensions);

            $filename = FileUtil::getTemporaryFilename(extension: 'webp');
            $imageAdapter->saveImageAs($image, $filename, 'webp', 80);

            $fileThumbnail = FileThumbnailEditor::createFromTemporaryFile($file, $format, $filename);
            $processor->adoptThumbnail($fileThumbnail);
        }
    }

    public function delete(array $files): void
    {
        $fileIDs = \array_column($files, 'fileID');

        $conditions = new PreparedStatementConditionBuilder();
        $conditions->add('fileID IN (?)', [$fileIDs]);

        $sql = "SELECT  thumbnailID
                FROM    wcf1_file_thumbnail
                {$conditions}";
        $statement = WCF::getDB()->prepare($sql);
        $statement->execute($conditions->getParameters());
        $thumbnailIDs = $statement->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($this->objectTypes as $objectType) {
            $objectType->getProcessor()->delete($fileIDs, $thumbnailIDs);
        }
    }
}
