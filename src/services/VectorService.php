<?php

namespace eventiva\craftchatagent\services;

use Craft;
use yii\base\Component;

class VectorService extends Component
{
    private ?\SQLite3 $db = null;
    public array $lastDebugScores = [];

    private function getDb(): \SQLite3
    {
        if ($this->db === null) {
            $dbPath = Craft::$app->path->storagePath . '/chatbot-vectors.db';
            $this->db = new \SQLite3($dbPath);
            $this->db->enableExceptions(true);
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->createSchema();
        }
        return $this->db;
    }

    private function createSchema(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS embeddings (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                entry_id       INTEGER NOT NULL,
                section_handle TEXT NOT NULL,
                chunk_index    INTEGER NOT NULL DEFAULT 0,
                chunk_text     TEXT NOT NULL,
                embedding      TEXT NOT NULL,
                metadata       TEXT,
                indexed_at     TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_entry_id ON embeddings (entry_id);
            CREATE INDEX IF NOT EXISTS idx_section  ON embeddings (section_handle);
            CREATE TABLE IF NOT EXISTS file_documents (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                original_name TEXT NOT NULL,
                file_type     TEXT NOT NULL,
                file_size     INTEGER NOT NULL,
                content       TEXT NOT NULL,
                chunk_count   INTEGER DEFAULT 0,
                indexed_at    TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS crawl_urls (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                url          TEXT NOT NULL UNIQUE,
                title        TEXT,
                status       TEXT NOT NULL DEFAULT \'pending\',
                chunk_count  INTEGER DEFAULT 0,
                last_crawled TEXT,
                error_msg    TEXT
            );
            CREATE TABLE IF NOT EXISTS qa_documents (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                question       TEXT NOT NULL,
                answer         TEXT NOT NULL,
                source         TEXT NOT NULL DEFAULT \'manual\',
                source_msg_id  INTEGER,
                is_active      INTEGER NOT NULL DEFAULT 1,
                created_at     TEXT NOT NULL,
                updated_at     TEXT NOT NULL
            );
        ');
    }

    public function storeChunks(int $entryId, string $section, array $chunks): void
    {
        $db = $this->getDb();

        // Delete existing chunks for this entry
        $stmt = $db->prepare('DELETE FROM embeddings WHERE entry_id = :entry_id');
        $stmt->bindValue(':entry_id', $entryId, SQLITE3_INTEGER);
        $stmt->execute();

        // Insert new chunks
        $insertStmt = $db->prepare('
            INSERT INTO embeddings (entry_id, section_handle, chunk_index, chunk_text, embedding, metadata, indexed_at)
            VALUES (:entry_id, :section, :chunk_index, :chunk_text, :embedding, :metadata, :indexed_at)
        ');

        foreach ($chunks as $i => $chunk) {
            $insertStmt->bindValue(':entry_id', $entryId, SQLITE3_INTEGER);
            $insertStmt->bindValue(':section', $section, SQLITE3_TEXT);
            $insertStmt->bindValue(':chunk_index', $i, SQLITE3_INTEGER);
            $insertStmt->bindValue(':chunk_text', $chunk['text'], SQLITE3_TEXT);
            $insertStmt->bindValue(':embedding', json_encode($chunk['embedding']), SQLITE3_TEXT);
            $insertStmt->bindValue(':metadata', isset($chunk['metadata']) ? json_encode($chunk['metadata']) : null, SQLITE3_TEXT);
            $insertStmt->bindValue(':indexed_at', date('c'), SQLITE3_TEXT);
            $insertStmt->execute();
            $insertStmt->reset();
        }
    }

    public function searchSimilar(array $queryEmbedding, int $topK = 5, float $minScore = 0.65, bool $debug = false): array
    {
        $db = $this->getDb();
        $result = $db->query('SELECT chunk_text, embedding, metadata FROM embeddings');

        $scored = [];
        $allScores = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $embedding = json_decode($row['embedding'], true);
            if (!is_array($embedding)) {
                continue;
            }
            $score = $this->cosineSimilarity($queryEmbedding, $embedding);
            if ($debug) {
                $allScores[] = round($score, 4);
            }
            if ($score >= $minScore) {
                $scored[] = [
                    'chunk_text' => $row['chunk_text'],
                    'metadata'   => $row['metadata'] ? json_decode($row['metadata'], true) : [],
                    'score'      => $score,
                ];
            }
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        if ($debug) {
            rsort($allScores);
            $this->lastDebugScores = $allScores;
            Craft::warning('VectorService searchSimilar: minScore=' . $minScore . ', queryDim=' . count($queryEmbedding) . ', totalRows=' . count($allScores) . ', topScores=' . implode(', ', array_slice($allScores, 0, 5)), __METHOD__);
        }

        return array_slice($scored, 0, $topK);
    }

    public function deleteByEntry(int $entryId): void
    {
        $db = $this->getDb();
        $stmt = $db->prepare('DELETE FROM embeddings WHERE entry_id = :entry_id');
        $stmt->bindValue(':entry_id', $entryId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    /** Clear only Craft-entry chunks (leaves file, URL and Q&A chunks intact). */
    public function clearAll(): int
    {
        $db    = $this->getDb();
        $count = (int)$db->querySingle("SELECT COUNT(*) FROM embeddings WHERE section_handle NOT IN ('__file__','__url__','__qa__')");
        $db->exec("DELETE FROM embeddings WHERE section_handle NOT IN ('__file__','__url__','__qa__')");
        return $count;
    }

    /** Stats for Craft Entries only (excludes file, URL and Q&A chunks). */
    public function getStats(): array
    {
        $db = $this->getDb();
        $totalChunks  = (int)$db->querySingle("SELECT COUNT(*) FROM embeddings WHERE section_handle NOT IN ('__file__','__url__','__qa__')");
        $totalEntries = (int)$db->querySingle("SELECT COUNT(DISTINCT entry_id) FROM embeddings WHERE section_handle NOT IN ('__file__','__url__','__qa__')");
        $result       = $db->query("SELECT DISTINCT section_handle FROM embeddings WHERE section_handle NOT IN ('__file__','__url__','__qa__')");
        $sections     = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sections[] = $row['section_handle'];
        }
        $lastIndexed = $db->querySingle("SELECT MAX(indexed_at) FROM embeddings WHERE section_handle NOT IN ('__file__','__url__','__qa__')");

        return [
            'totalChunks'  => $totalChunks,
            'totalEntries' => $totalEntries,
            'sections'     => $sections,
            'lastIndexed'  => $lastIndexed,
        ];
    }

    /** List of indexed Craft entries (excludes file, URL and Q&A chunks). */
    public function getEntrySummary(): array
    {
        $db     = $this->getDb();
        $result = $db->query("
            SELECT entry_id, section_handle, COUNT(*) as chunk_count, MAX(indexed_at) as last_indexed, MIN(metadata) as sample_meta
            FROM embeddings
            WHERE section_handle NOT IN ('__file__','__url__','__qa__')
            GROUP BY entry_id, section_handle
            ORDER BY last_indexed DESC
        ");

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $meta   = $row['sample_meta'] ? json_decode($row['sample_meta'], true) : [];
            $rows[] = [
                'entry_id'    => (int)$row['entry_id'],
                'section'     => $row['section_handle'],
                'chunk_count' => (int)$row['chunk_count'],
                'last_indexed'=> $row['last_indexed'],
                'title'       => $meta['entryTitle'] ?? ('Entry #' . $row['entry_id']),
                'url'         => $meta['url'] ?? '',
            ];
        }

        return $rows;
    }

    public function getEntryChunks(int $entryId): array
    {
        $db = $this->getDb();
        $stmt = $db->prepare('
            SELECT id, chunk_index, chunk_text, embedding, metadata, indexed_at
            FROM embeddings
            WHERE entry_id = :entry_id
            ORDER BY chunk_index ASC
        ');
        $stmt->bindValue(':entry_id', $entryId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $embedding = json_decode($row['embedding'], true);
            $rows[] = [
                'id'           => (int)$row['id'],
                'chunk_index'  => (int)$row['chunk_index'],
                'chunk_text'   => $row['chunk_text'],
                'embedding_preview' => array_slice($embedding ?? [], 0, 5),
                'metadata'     => $row['metadata'] ? json_decode($row['metadata'], true) : [],
                'indexed_at'   => $row['indexed_at'],
            ];
        }

        return $rows;
    }

    // ── File Document Methods ──────────────────────────────────────────

    public function storeFileDocument(string $originalName, string $fileType, int $fileSize, string $content): int
    {
        $db = $this->getDb();
        $stmt = $db->prepare('
            INSERT INTO file_documents (original_name, file_type, file_size, content, chunk_count, indexed_at)
            VALUES (:name, :type, :size, :content, 0, :indexed_at)
        ');
        $stmt->bindValue(':name', $originalName, SQLITE3_TEXT);
        $stmt->bindValue(':type', $fileType, SQLITE3_TEXT);
        $stmt->bindValue(':size', $fileSize, SQLITE3_INTEGER);
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt->bindValue(':indexed_at', date('c'), SQLITE3_TEXT);
        $stmt->execute();
        return (int)$db->lastInsertRowID();
    }

    public function storeFileChunks(int $fileId, array $chunks): void
    {
        $db = $this->getDb();

        $del = $db->prepare("DELETE FROM embeddings WHERE entry_id = :id AND section_handle = '__file__'");
        $del->bindValue(':id', $fileId, SQLITE3_INTEGER);
        $del->execute();

        $ins = $db->prepare('
            INSERT INTO embeddings (entry_id, section_handle, chunk_index, chunk_text, embedding, metadata, indexed_at)
            VALUES (:entry_id, \'__file__\', :chunk_index, :chunk_text, :embedding, :metadata, :indexed_at)
        ');

        foreach ($chunks as $i => $chunk) {
            $ins->bindValue(':entry_id', $fileId, SQLITE3_INTEGER);
            $ins->bindValue(':chunk_index', $i, SQLITE3_INTEGER);
            $ins->bindValue(':chunk_text', $chunk['text'], SQLITE3_TEXT);
            $ins->bindValue(':embedding', json_encode($chunk['embedding']), SQLITE3_TEXT);
            $ins->bindValue(':metadata', isset($chunk['metadata']) ? json_encode($chunk['metadata']) : null, SQLITE3_TEXT);
            $ins->bindValue(':indexed_at', date('c'), SQLITE3_TEXT);
            $ins->execute();
            $ins->reset();
        }
    }

    public function updateFileDocumentChunkCount(int $fileId, int $chunkCount): void
    {
        $db = $this->getDb();
        $stmt = $db->prepare('UPDATE file_documents SET chunk_count = :cnt, indexed_at = :at WHERE id = :id');
        $stmt->bindValue(':cnt', $chunkCount, SQLITE3_INTEGER);
        $stmt->bindValue(':at', date('c'), SQLITE3_TEXT);
        $stmt->bindValue(':id', $fileId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function deleteFileDocument(int $fileId): void
    {
        $db = $this->getDb();
        $del = $db->prepare("DELETE FROM embeddings WHERE entry_id = :id AND section_handle = '__file__'");
        $del->bindValue(':id', $fileId, SQLITE3_INTEGER);
        $del->execute();
        $del2 = $db->prepare('DELETE FROM file_documents WHERE id = :id');
        $del2->bindValue(':id', $fileId, SQLITE3_INTEGER);
        $del2->execute();
    }

    public function getFileDocuments(): array
    {
        $db = $this->getDb();
        $result = $db->query('SELECT id, original_name, file_type, file_size, chunk_count, indexed_at FROM file_documents ORDER BY indexed_at DESC');
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = [
                'id'            => (int)$row['id'],
                'original_name' => $row['original_name'],
                'file_type'     => $row['file_type'],
                'file_size'     => (int)$row['file_size'],
                'chunk_count'   => (int)$row['chunk_count'],
                'indexed_at'    => $row['indexed_at'],
            ];
        }
        return $rows;
    }

    public function getFileStats(): array
    {
        $db = $this->getDb();
        $totalFiles  = (int)$db->querySingle('SELECT COUNT(*) FROM file_documents');
        $totalChunks = (int)$db->querySingle("SELECT COUNT(*) FROM embeddings WHERE section_handle = '__file__'");
        $lastIndexed = $db->querySingle('SELECT MAX(indexed_at) FROM file_documents');
        return [
            'totalFiles'  => $totalFiles,
            'totalChunks' => $totalChunks,
            'lastIndexed' => $lastIndexed ?: null,
        ];
    }

    public function getFileContent(int $fileId): ?string
    {
        $db = $this->getDb();
        $stmt = $db->prepare('SELECT content FROM file_documents WHERE id = :id');
        $stmt->bindValue(':id', $fileId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ? $row['content'] : null;
    }

    public function getFileDocument(int $fileId): ?array
    {
        $db = $this->getDb();
        $stmt = $db->prepare('SELECT id, original_name, file_type, file_size, chunk_count, indexed_at FROM file_documents WHERE id = :id');
        $stmt->bindValue(':id', $fileId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if (!$row) return null;
        return [
            'id'            => (int)$row['id'],
            'original_name' => $row['original_name'],
            'file_type'     => $row['file_type'],
            'file_size'     => (int)$row['file_size'],
            'chunk_count'   => (int)$row['chunk_count'],
            'indexed_at'    => $row['indexed_at'],
        ];
    }

    // ── Crawl URL Methods ──────────────────────────────────────────────

    /**
     * Add URLs to the crawl list (INSERT OR IGNORE for duplicates).
     * Returns number of newly added URLs.
     */
    public function addCrawlUrls(array $urls): int
    {
        $db    = $this->getDb();
        $stmt  = $db->prepare('INSERT OR IGNORE INTO crawl_urls (url, status) VALUES (:url, \'pending\')');
        $added = 0;
        foreach ($urls as $url) {
            $url = trim($url);
            if (!$url) continue;
            $stmt->bindValue(':url', $url, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->reset();
            if ($db->changes() > 0) $added++;
        }
        return $added;
    }

    public function getCrawlUrls(): array
    {
        $db     = $this->getDb();
        $result = $db->query('SELECT id, url, title, status, chunk_count, last_crawled, error_msg FROM crawl_urls ORDER BY id DESC');
        $rows   = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = [
                'id'           => (int)$row['id'],
                'url'          => $row['url'],
                'title'        => $row['title'],
                'status'       => $row['status'],
                'chunk_count'  => (int)$row['chunk_count'],
                'last_crawled' => $row['last_crawled'],
                'error_msg'    => $row['error_msg'],
            ];
        }
        return $rows;
    }

    public function getCrawlStats(): array
    {
        $db      = $this->getDb();
        $total   = (int)$db->querySingle('SELECT COUNT(*) FROM crawl_urls');
        $indexed = (int)$db->querySingle("SELECT COUNT(*) FROM crawl_urls WHERE status = 'indexed'");
        $pending = (int)$db->querySingle("SELECT COUNT(*) FROM crawl_urls WHERE status = 'pending'");
        $errors  = (int)$db->querySingle("SELECT COUNT(*) FROM crawl_urls WHERE status = 'error'");
        $chunks  = (int)$db->querySingle("SELECT COUNT(*) FROM embeddings WHERE section_handle = '__url__'");
        $last    = $db->querySingle("SELECT MAX(last_crawled) FROM crawl_urls WHERE status = 'indexed'");
        return [
            'total'       => $total,
            'indexed'     => $indexed,
            'pending'     => $pending,
            'errors'      => $errors,
            'totalChunks' => $chunks,
            'lastCrawled' => $last ?: null,
        ];
    }

    public function clearAllUrls(): int
    {
        $db    = $this->getDb();
        $count = (int)$db->querySingle("SELECT COUNT(*) FROM crawl_urls");
        $db->exec("DELETE FROM embeddings WHERE section_handle = '__url__'");
        $db->exec("DELETE FROM crawl_urls");
        return $count;
    }

    public function deleteCrawlUrl(int $id): void
    {
        $db = $this->getDb();
        $d1 = $db->prepare("DELETE FROM embeddings WHERE entry_id = :id AND section_handle = '__url__'");
        $d1->bindValue(':id', $id, SQLITE3_INTEGER);
        $d1->execute();
        $d2 = $db->prepare('DELETE FROM crawl_urls WHERE id = :id');
        $d2->bindValue(':id', $id, SQLITE3_INTEGER);
        $d2->execute();
    }

    public function updateCrawlUrlStatus(int $id, string $status, int $chunkCount = 0, string $title = '', ?string $error = null): void
    {
        $db   = $this->getDb();
        $stmt = $db->prepare('UPDATE crawl_urls SET status = :status, chunk_count = :cnt, last_crawled = :at, title = :title, error_msg = :err WHERE id = :id');
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':cnt', $chunkCount, SQLITE3_INTEGER);
        $stmt->bindValue(':at', date('c'), SQLITE3_TEXT);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':err', $error, SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function storeUrlChunks(int $urlId, array $chunks): void
    {
        $db = $this->getDb();
        $del = $db->prepare("DELETE FROM embeddings WHERE entry_id = :id AND section_handle = '__url__'");
        $del->bindValue(':id', $urlId, SQLITE3_INTEGER);
        $del->execute();

        $ins = $db->prepare('
            INSERT INTO embeddings (entry_id, section_handle, chunk_index, chunk_text, embedding, metadata, indexed_at)
            VALUES (:entry_id, \'__url__\', :chunk_index, :chunk_text, :embedding, :metadata, :indexed_at)
        ');
        foreach ($chunks as $i => $chunk) {
            $ins->bindValue(':entry_id', $urlId, SQLITE3_INTEGER);
            $ins->bindValue(':chunk_index', $i, SQLITE3_INTEGER);
            $ins->bindValue(':chunk_text', $chunk['text'], SQLITE3_TEXT);
            $ins->bindValue(':embedding', json_encode($chunk['embedding']), SQLITE3_TEXT);
            $ins->bindValue(':metadata', isset($chunk['metadata']) ? json_encode($chunk['metadata']) : null, SQLITE3_TEXT);
            $ins->bindValue(':indexed_at', date('c'), SQLITE3_TEXT);
            $ins->execute();
            $ins->reset();
        }
    }

    public function getUrlChunks(int $urlId): array
    {
        $db   = $this->getDb();
        $stmt = $db->prepare("
            SELECT id, chunk_index, chunk_text, embedding, metadata, indexed_at
            FROM embeddings
            WHERE entry_id = :id AND section_handle = '__url__'
            ORDER BY chunk_index ASC
        ");
        $stmt->bindValue(':id', $urlId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $embedding = json_decode($row['embedding'], true);
            $rows[] = [
                'id'               => (int)$row['id'],
                'chunk_index'      => (int)$row['chunk_index'],
                'chunk_text'       => $row['chunk_text'],
                'embedding_preview'=> array_slice($embedding ?? [], 0, 5),
                'metadata'         => $row['metadata'] ? json_decode($row['metadata'], true) : [],
                'indexed_at'       => $row['indexed_at'],
            ];
        }

        return $rows;
    }

    public function getCrawlUrl(int $id): ?array
    {
        $db   = $this->getDb();
        $stmt = $db->prepare('SELECT id, url, title, status, chunk_count, last_crawled, error_msg FROM crawl_urls WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row) return null;
        return [
            'id'           => (int)$row['id'],
            'url'          => $row['url'],
            'title'        => $row['title'],
            'status'       => $row['status'],
            'chunk_count'  => (int)$row['chunk_count'],
            'last_crawled' => $row['last_crawled'],
            'error_msg'    => $row['error_msg'],
        ];
    }

    // ── Q&A Document Methods ───────────────────────────────────────────

    /**
     * Create or update a Q&A pair. Returns the Q&A document ID.
     */
    public function saveQaPair(
        ?int   $id,
        string $question,
        string $answer,
        string $source      = 'manual',
        ?int   $sourceMsgId = null
    ): int {
        $db  = $this->getDb();
        $now = date('c');

        if ($id) {
            $stmt = $db->prepare('
                UPDATE qa_documents
                SET question = :q, answer = :a, source = :src, source_msg_id = :sid, updated_at = :now
                WHERE id = :id
            ');
            $stmt->bindValue(':q',   $question,    SQLITE3_TEXT);
            $stmt->bindValue(':a',   $answer,      SQLITE3_TEXT);
            $stmt->bindValue(':src', $source,      SQLITE3_TEXT);
            $stmt->bindValue(':sid', $sourceMsgId, SQLITE3_INTEGER);
            $stmt->bindValue(':now', $now,          SQLITE3_TEXT);
            $stmt->bindValue(':id',  $id,           SQLITE3_INTEGER);
            $stmt->execute();
            return $id;
        }

        $stmt = $db->prepare('
            INSERT INTO qa_documents (question, answer, source, source_msg_id, is_active, created_at, updated_at)
            VALUES (:q, :a, :src, :sid, 1, :now, :now)
        ');
        $stmt->bindValue(':q',   $question,    SQLITE3_TEXT);
        $stmt->bindValue(':a',   $answer,      SQLITE3_TEXT);
        $stmt->bindValue(':src', $source,      SQLITE3_TEXT);
        $stmt->bindValue(':sid', $sourceMsgId, SQLITE3_INTEGER);
        $stmt->bindValue(':now', $now,          SQLITE3_TEXT);
        $stmt->execute();
        return (int)$db->lastInsertRowID();
    }

    public function deleteQaPair(int $id): void
    {
        $db = $this->getDb();
        $d1 = $db->prepare("DELETE FROM embeddings WHERE entry_id = :id AND section_handle = '__qa__'");
        $d1->bindValue(':id', $id, SQLITE3_INTEGER);
        $d1->execute();
        $d2 = $db->prepare('DELETE FROM qa_documents WHERE id = :id');
        $d2->bindValue(':id', $id, SQLITE3_INTEGER);
        $d2->execute();
    }

    public function toggleQaPair(int $id, bool $active): void
    {
        $db   = $this->getDb();
        $stmt = $db->prepare('UPDATE qa_documents SET is_active = :v, updated_at = :now WHERE id = :id');
        $stmt->bindValue(':v',   $active ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':now', date('c'),        SQLITE3_TEXT);
        $stmt->bindValue(':id',  $id,              SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function getQaPairs(): array
    {
        $db     = $this->getDb();
        $result = $db->query('SELECT id, question, answer, source, source_msg_id, is_active, created_at, updated_at FROM qa_documents ORDER BY created_at DESC');
        $rows   = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = [
                'id'            => (int)$row['id'],
                'question'      => $row['question'],
                'answer'        => $row['answer'],
                'source'        => $row['source'],
                'source_msg_id' => $row['source_msg_id'] ? (int)$row['source_msg_id'] : null,
                'is_active'     => (bool)$row['is_active'],
                'created_at'    => $row['created_at'],
                'updated_at'    => $row['updated_at'],
            ];
        }
        return $rows;
    }

    public function getQaPair(int $id): ?array
    {
        $db   = $this->getDb();
        $stmt = $db->prepare('SELECT id, question, answer, source, source_msg_id, is_active, created_at, updated_at FROM qa_documents WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row) return null;
        return [
            'id'            => (int)$row['id'],
            'question'      => $row['question'],
            'answer'        => $row['answer'],
            'source'        => $row['source'],
            'source_msg_id' => $row['source_msg_id'] ? (int)$row['source_msg_id'] : null,
            'is_active'     => (bool)$row['is_active'],
            'created_at'    => $row['created_at'],
            'updated_at'    => $row['updated_at'],
        ];
    }

    public function getQaStats(): array
    {
        $db      = $this->getDb();
        $total   = (int)$db->querySingle('SELECT COUNT(*) FROM qa_documents');
        $active  = (int)$db->querySingle('SELECT COUNT(*) FROM qa_documents WHERE is_active = 1');
        $manual  = (int)$db->querySingle("SELECT COUNT(*) FROM qa_documents WHERE source = 'manual'");
        $fromLog = (int)$db->querySingle("SELECT COUNT(*) FROM qa_documents WHERE source = 'log'");
        $last    = $db->querySingle('SELECT MAX(updated_at) FROM qa_documents');
        return [
            'total'      => $total,
            'active'     => $active,
            'fromManual' => $manual,
            'fromLog'    => $fromLog,
            'lastSaved'  => $last ?: null,
        ];
    }

    /** Returns all source_msg_ids that have been used as Q&A training source. */
    public function getQaSourceMsgIds(): array
    {
        $db     = $this->getDb();
        $result = $db->query('SELECT source_msg_id FROM qa_documents WHERE source_msg_id IS NOT NULL');
        $ids    = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $ids[] = (int)$row['source_msg_id'];
        }
        return $ids;
    }

    public function storeQaEmbedding(int $qaId, array $embedding, string $combinedText, string $question): void
    {
        $db = $this->getDb();

        $del = $db->prepare("DELETE FROM embeddings WHERE entry_id = :id AND section_handle = '__qa__'");
        $del->bindValue(':id', $qaId, SQLITE3_INTEGER);
        $del->execute();

        $ins = $db->prepare('
            INSERT INTO embeddings (entry_id, section_handle, chunk_index, chunk_text, embedding, metadata, indexed_at)
            VALUES (:entry_id, \'__qa__\', 0, :chunk_text, :embedding, :metadata, :indexed_at)
        ');
        $ins->bindValue(':entry_id',   $qaId,                    SQLITE3_INTEGER);
        $ins->bindValue(':chunk_text', $combinedText,             SQLITE3_TEXT);
        $ins->bindValue(':embedding',  json_encode($embedding),   SQLITE3_TEXT);
        $ins->bindValue(':metadata',   json_encode(['type' => 'qa', 'question' => $question, 'qa_id' => $qaId]), SQLITE3_TEXT);
        $ins->bindValue(':indexed_at', date('c'),                 SQLITE3_TEXT);
        $ins->execute();
    }

    // ── Private Helpers ────────────────────────────────────────────────

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot  += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }
        $denom = sqrt($magA) * sqrt($magB);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    public function __destruct()
    {
        if ($this->db !== null) {
            $this->db->close();
        }
    }
}
