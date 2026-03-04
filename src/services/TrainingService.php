<?php

namespace eventiva\craftchatagent\services;

use Craft;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\fields\Textarea;
use craft\fields\Table;
use eventiva\craftchatagent\Chatagent;
use yii\base\Component;

class TrainingService extends Component
{
    /**
     * Train all configured sections.
     */
    public function trainAll(): array
    {
        $settings = Chatagent::$instance->getChatService()->getSettings();
        $sections = $settings['trainingSections'] ?? [];

        $result = ['processed' => 0, 'chunks' => 0, 'errors' => []];

        foreach ($sections as $handle) {
            $sectionResult = $this->trainSection($handle);
            $result['processed'] += $sectionResult['processed'];
            $result['chunks']    += $sectionResult['chunks'];
            $result['errors']    = array_merge($result['errors'], $sectionResult['errors']);
        }

        return $result;
    }

    /**
     * Train all published entries of a given section handle.
     */
    public function trainSection(string $sectionHandle): array
    {
        $sectionRecord = \craft\records\Section::findOne(['handle' => $sectionHandle]);
        if (!$sectionRecord) {
            return ['processed' => 0, 'chunks' => 0, 'errors' => ["Section '{$sectionHandle}' nicht gefunden."]];
        }

        $entries = Entry::find()
            ->section($sectionHandle)
            ->status('live')
            ->limit(null)
            ->all();

        $result = ['processed' => 0, 'chunks' => 0, 'errors' => []];

        foreach ($entries as $entry) {
            try {
                $count = $this->trainEntry($entry);
                $result['processed']++;
                $result['chunks'] += $count;
            } catch (\Throwable $e) {
                $result['errors'][] = "Entry #{$entry->id} ({$entry->title}): " . $e->getMessage();
                Craft::error("TrainingService::trainSection error for entry #{$entry->id}: " . $e->getMessage(), __METHOD__);
            }
        }

        return $result;
    }

    /**
     * Train a single entry: extract text → chunk → embed → store.
     *
     * @return int Number of chunks stored
     */
    public function trainEntry(Entry $entry): int
    {
        $text = $this->extractText($entry);

        if (empty(trim($text))) {
            return 0;
        }

        $embeddingService = Chatagent::$instance->getEmbeddingService();
        $vectorService    = Chatagent::$instance->getVectorService();

        $chunkTexts = $embeddingService->chunkText($text);

        if (empty($chunkTexts)) {
            return 0;
        }

        $metadata = [
            'entryTitle' => $entry->title,
            'url'        => $entry->getUrl() ?? '',
            'siteId'     => $entry->siteId,
        ];

        $sectionHandle = $entry->getSection()->handle ?? 'unknown';

        $chunks = [];
        foreach ($chunkTexts as $chunkText) {
            $embedding = $embeddingService->embed($chunkText);
            $chunks[]  = [
                'text'      => $chunkText,
                'embedding' => $embedding,
                'metadata'  => $metadata,
            ];
        }

        $vectorService->storeChunks($entry->id, $sectionHandle, $chunks);

        return count($chunks);
    }

    /**
     * Train uploaded file content: chunk → embed → store.
     *
     * @return int Number of chunks stored
     */
    public function trainFileContent(int $fileId, string $content, string $filename): int
    {
        // Strip HTML / Markdown artefacts
        $text = strip_tags($content);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\r\n/', "\n", $text);
        $text = trim($text);

        if (empty($text)) {
            return 0;
        }

        $embeddingService = Chatagent::$instance->getEmbeddingService();
        $vectorService    = Chatagent::$instance->getVectorService();

        $chunkTexts = $embeddingService->chunkText($text);

        if (empty($chunkTexts)) {
            return 0;
        }

        $metadata = [
            'filename' => $filename,
            'source'   => 'file',
        ];

        $chunks = [];
        foreach ($chunkTexts as $chunkText) {
            $embedding = $embeddingService->embed($chunkText);
            $chunks[]  = [
                'text'      => $chunkText,
                'embedding' => $embedding,
                'metadata'  => $metadata,
            ];
        }

        $vectorService->storeFileChunks($fileId, $chunks);
        $vectorService->updateFileDocumentChunkCount($fileId, count($chunks));

        return count($chunks);
    }

    /**
     * Extract plain text from an entry (title + all text fields).
     */
    private function extractText(Entry $entry): string
    {
        $parts = [];

        if ($entry->title) {
            $parts[] = $entry->title;
        }

        $fieldLayout = $entry->getFieldLayout();
        if (!$fieldLayout) {
            return implode("\n\n", $parts);
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            try {
                $value = $entry->getFieldValue($field->handle);

                if ($field instanceof PlainText || $field instanceof Textarea) {
                    if ($value && is_string($value)) {
                        $parts[] = $value;
                    }
                } elseif ($field instanceof Table) {
                    // Table field: iterate rows
                    if (is_array($value)) {
                        foreach ($value as $row) {
                            if (is_array($row)) {
                                $rowParts = [];
                                foreach ($row as $cell) {
                                    if (is_string($cell) && $cell !== '') {
                                        $rowParts[] = $cell;
                                    }
                                }
                                if ($rowParts) {
                                    $parts[] = implode(' | ', $rowParts);
                                }
                            }
                        }
                    }
                } else {
                    // Skip relational fields (Assets, Entries, etc.) – they return class name when cast
                    if ($value instanceof \craft\elements\db\ElementQuery || $value instanceof \craft\db\Query) {
                        continue;
                    }
                    // Try to convert to string (covers CKEditor/Redactor, Matrix summary, etc.)
                    $str = (string)$value;
                    if ($str !== '') {
                        // Strip HTML tags for rich-text fields
                        $stripped = strip_tags($str);
                        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $stripped = preg_replace('/\s+/', ' ', $stripped);
                        $stripped = trim($stripped);
                        if ($stripped !== '') {
                            $parts[] = $stripped;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Skip fields that can't be converted
            }
        }

        return implode("\n\n", $parts);
    }
}
