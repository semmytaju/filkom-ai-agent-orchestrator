<?php

class RagService {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
    }

    public function listKnowledgeBases(): array {
        if (!$this->db->isConnected()) return [];
        $st = $this->db->runPrepared(
            "SELECT kb.*, COUNT(DISTINCT d.id) AS doc_count
             FROM tbl_knowledge_bases kb
             LEFT JOIN tbl_knowledge_documents d ON d.knowledge_base_id = kb.id AND d.is_active = 1
             GROUP BY kb.id
             ORDER BY kb.id ASC"
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function saveKnowledgeBase(array $data, string $actor = 'admin'): array {
        if (!$this->db->isConnected()) return ['success' => false, 'message' => 'Database unavailable.'];
        $id = (int)($data['id'] ?? 0);
        $code = strtoupper(preg_replace('/[^A-Z0-9_]/', '_', trim((string)($data['kb_code'] ?? ''))));
        $name = trim((string)($data['kb_name'] ?? ''));
        $cat = trim((string)($data['category'] ?? 'Academic')) ?: 'Academic';
        $desc = trim((string)($data['description'] ?? ''));
        $active = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

        if (strlen($code) < 3 || strlen($name) < 3) {
            return ['success' => false, 'message' => 'Knowledge base code and name are required.'];
        }

        if ($id > 0) {
            $this->db->prepare("UPDATE tbl_knowledge_bases SET kb_code = :c, kb_name = :n, category = :cat, description = :d, is_active = :ia WHERE id = :id");
            $this->db->bind(':id', $id, PDO::PARAM_INT);
        } else {
            $this->db->prepare("INSERT INTO tbl_knowledge_bases (kb_code, kb_name, category, description, is_active, created_by) VALUES (:c, :n, :cat, :d, :ia, :cb)");
            $this->db->bind(':cb', $actor);
        }
        $this->db->bind(':c', $code);
        $this->db->bind(':n', $name);
        $this->db->bind(':cat', $cat);
        $this->db->bind(':d', $desc);
        $this->db->bind(':ia', $active, PDO::PARAM_INT);
        $ok = $this->db->execute();
        $kbId = $id > 0 ? $id : $this->db->lastInsertId();

        if ($ok && !empty($data['role_ids']) && is_array($data['role_ids'])) {
            $this->saveKnowledgeBasePermissions($kbId, $data['role_ids']);
        }
        return ['success' => $ok, 'knowledge_base_id' => $kbId, 'message' => $ok ? 'Knowledge base saved.' : 'Failed to save knowledge base.'];
    }

    public function saveKnowledgeBasePermissions(int $kbId, array $roleIds): void {
        $this->db->runPrepared("DELETE FROM tbl_document_access_permissions WHERE knowledge_base_id = ? AND document_id IS NULL", [$kbId]);
        $pdo = $this->db->getPdo();
        if (!$pdo) return;
        $st = $pdo->prepare("INSERT INTO tbl_document_access_permissions (knowledge_base_id, document_id, role_id, can_read, can_manage) VALUES (?, NULL, ?, 1, ?)");
        foreach (array_unique(array_map('intval', $roleIds)) as $rid) {
            if ($rid > 0) {
                $st->execute([$kbId, $rid, $rid === 1 ? 1 : 0]);
            }
        }
    }

    public function listDocuments(array $params = [], ?array $userContext = null): array {
        if (!$this->db->isConnected()) return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(5, min(100, (int)($params['per_page'] ?? 12)));
        $search = trim((string)($params['search'] ?? ''));
        $kbFilter = (int)($params['knowledge_base_id'] ?? ($params['filter1'] ?? 0));
        $typeFilter = strtoupper(trim((string)($params['file_type'] ?? ($params['filter2'] ?? ''))));

        $where = ['1=1'];
        $binds = [];
        if ($userContext && empty($userContext['scope']['is_admin'])) {
            $roleIds = array_map('intval', $userContext['role_ids'] ?? []);
            if (empty($roleIds)) return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
            $ph = implode(',', array_fill(0, count($roleIds), '?'));
            $where[] = "(EXISTS (SELECT 1 FROM tbl_document_access_permissions dap WHERE dap.document_id = d.id AND dap.can_read = 1 AND dap.role_id IN ({$ph}))
                         OR EXISTS (SELECT 1 FROM tbl_document_access_permissions dap2 WHERE dap2.knowledge_base_id = d.knowledge_base_id AND dap2.document_id IS NULL AND dap2.can_read = 1 AND dap2.role_id IN ({$ph})))";
            $binds = array_merge($binds, $roleIds, $roleIds);
        }
        if ($search !== '') {
            $where[] = '(d.doc_code LIKE ? OR d.title LIKE ? OR d.file_name LIKE ? OR d.category LIKE ?)';
            $like = '%' . $search . '%';
            array_push($binds, $like, $like, $like, $like);
        }
        if ($kbFilter > 0) {
            $where[] = 'd.knowledge_base_id = ?';
            $binds[] = $kbFilter;
        }
        if (in_array($typeFilter, ['PDF', 'DOCX', 'XLSX', 'TXT'], true)) {
            $where[] = 'd.file_type = ?';
            $binds[] = $typeFilter;
        }

        $whereSql = implode(' AND ', $where);
        $stCnt = $this->db->runPrepared("SELECT COUNT(*) FROM tbl_knowledge_documents d WHERE {$whereSql}", $binds);
        $total = $stCnt ? (int)$stCnt->fetchColumn() : 0;
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        $qBinds = array_merge($binds, [$perPage, $offset]);
        $stRows = $this->db->runPrepared(
            "SELECT d.id, d.doc_code, d.knowledge_base_id, d.title, d.category, d.file_name, d.file_type,
                    d.file_size_bytes, d.chunk_count, d.retrieval_count, d.is_active, d.uploaded_by, d.created_at,
                    kb.kb_code, kb.kb_name
             FROM tbl_knowledge_documents d
             LEFT JOIN tbl_knowledge_bases kb ON kb.id = d.knowledge_base_id
             WHERE {$whereSql}
             ORDER BY d.id DESC
             LIMIT ? OFFSET ?",
            $qBinds
        );

        return [
            'items' => $stRows ? $stRows->fetchAll(PDO::FETCH_ASSOC) : [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ];
    }

    public function saveDocument(array $data, string $actor = 'admin'): array {
        if (!$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Database unavailable.'];
        }
        $id = (int)($data['id'] ?? 0);
        $kbId = max(1, (int)($data['knowledge_base_id'] ?? 1));
        $title = trim((string)($data['title'] ?? ''));
        $category = trim((string)($data['category'] ?? 'Academic')) ?: 'Academic';
        $fileName = trim((string)($data['file_name'] ?? 'document.txt')) ?: 'document.txt';
        $fileType = strtoupper(trim((string)($data['file_type'] ?? 'TXT')));
        if ($fileType === 'DOC') $fileType = 'DOCX';
        if (!in_array($fileType, ['PDF', 'DOCX', 'XLSX', 'TXT'], true)) $fileType = 'TXT';
        $contentText = DocumentParser::normalizeExtractedText((string)($data['content_text'] ?? ''));
        $isActive = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

        if ($title === '' || mb_strlen($contentText, 'UTF-8') < 10) {
            return ['success' => false, 'message' => 'Document title and readable text (min 10 characters) are required.'];
        }
        if (!DocumentParser::isReadableDocumentText($contentText)) {
            return ['success' => false, 'message' => 'Document rejected: unreadable binary or garbled text detected.'];
        }

        $chunks = $this->chunkText($contentText, 520);
        $respSvc = new ResponseService($this->db);
        $canEmbed = $respSvc->hasConfiguredKey() && count($chunks) <= 20;

        try {
            $this->db->beginTransaction();

            if ($id > 0) {
                $this->db->prepare("UPDATE tbl_knowledge_documents
                    SET knowledge_base_id = :kb, title = :t, category = :c, file_name = :fn, file_type = :ft,
                        file_size_bytes = :fs, content_text = :ct, chunk_count = :cc, is_active = :ia
                    WHERE id = :id");
                $this->db->bind(':id', $id, PDO::PARAM_INT);
                $docId = $id;
            } else {
                $docCode = 'DOC-' . strtoupper(bin2hex(random_bytes(4)));
                $this->db->prepare("INSERT INTO tbl_knowledge_documents
                    (doc_code, knowledge_base_id, title, category, file_name, file_type, file_size_bytes, content_text, chunk_count, is_active, uploaded_by)
                    VALUES (:dc, :kb, :t, :c, :fn, :ft, :fs, :ct, :cc, :ia, :ub)");
                $this->db->bind(':dc', $docCode);
                $this->db->bind(':ub', $actor);
            }
            $this->db->bind(':kb', $kbId, PDO::PARAM_INT);
            $this->db->bind(':t', $title);
            $this->db->bind(':c', $category);
            $this->db->bind(':fn', $fileName);
            $this->db->bind(':ft', $fileType);
            $this->db->bind(':fs', strlen($contentText), PDO::PARAM_INT);
            $this->db->bind(':ct', $contentText);
            $this->db->bind(':cc', count($chunks), PDO::PARAM_INT);
            $this->db->bind(':ia', $isActive, PDO::PARAM_INT);
            $this->db->execute();

            if ($id <= 0) {
                $docId = $this->db->lastInsertId();
            } else {
                $this->db->runPrepared("DELETE FROM tbl_knowledge_chunks WHERE document_id = ?", [$docId]);
            }

            $pdo = $this->db->getPdo();
            $stChunk = $pdo->prepare("INSERT INTO tbl_knowledge_chunks (document_id, chunk_index, section_title, chunk_text, keywords_text, vector_json, embedding_json, embedding_model)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

            $embCount = 0;
            foreach ($chunks as $idx => $chText) {
                $secTitle = $title . ' — Section #' . ($idx + 1);
                if (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $chText, $m)) {
                    $secTitle = trim($m[1]);
                    $chText = trim($m[2]) ?: $chText;
                }
                $vec = $this->computeTfVector($secTitle . ' ' . $chText);
                $kw = implode(', ', array_slice(array_keys($vec), 0, 16));
                $embJson = null;
                $embModel = null;
                if ($canEmbed) {
                    $emb = $respSvc->createEmbedding($secTitle . "\n" . $chText, 'text-embedding-3-small');
                    if (is_array($emb) && !empty($emb)) {
                        $embJson = json_encode($emb);
                        $embModel = 'text-embedding-3-small';
                        $embCount++;
                    }
                }
                $stChunk->execute([$docId, $idx + 1, $secTitle, $chText, $kw, json_encode($vec), $embJson, $embModel]);
            }

            if (!empty($data['role_ids']) && is_array($data['role_ids'])) {
                $this->db->runPrepared("DELETE FROM tbl_document_access_permissions WHERE document_id = ?", [$docId]);
                $stPerm = $pdo->prepare("INSERT INTO tbl_document_access_permissions (knowledge_base_id, document_id, role_id, can_read, can_manage) VALUES (?, ?, ?, 1, ?)");
                foreach (array_unique(array_map('intval', $data['role_ids'])) as $rid) {
                    if ($rid > 0) {
                        $stPerm->execute([$kbId, $docId, $rid, $rid === 1 ? 1 : 0]);
                    }
                }
            }

            $this->db->commit();
            return [
                'success' => true,
                'document_id' => $docId,
                'chunk_count' => count($chunks),
                'embeddings_generated' => $embCount,
                'message' => "Document saved and indexed into " . count($chunks) . " chunk(s)."
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to index document: ' . $e->getMessage()];
        }
    }

    public function retrieveForAgent(string $query, int $agentId, array $userRoleIds, int $limit = 4, float $minScore = 0.12): array {
        if (!$this->db->isConnected() || trim($query) === '') return [];

        $isAdmin = in_array(1, array_map('intval', $userRoleIds), true);
        $kbIds = [];
        if ($agentId > 0) {
            $stKb = $this->db->runPrepared("SELECT knowledge_base_id FROM tbl_agent_knowledge WHERE agent_id = ? ORDER BY priority_order ASC", [$agentId]);
            if ($stKb) {
                $kbIds = array_map('intval', $stKb->fetchAll(PDO::FETCH_COLUMN));
            }
        }

        $where = ['d.is_active = 1'];
        $binds = [];
        if (!empty($kbIds)) {
            $phKb = implode(',', array_fill(0, count($kbIds), '?'));
            $where[] = "d.knowledge_base_id IN ({$phKb})";
            $binds = array_merge($binds, $kbIds);
        }
        if (!$isAdmin) {
            $rIds = array_values(array_filter(array_map('intval', $userRoleIds), static fn($v) => $v > 0));
            if (empty($rIds)) return [];
            $phR = implode(',', array_fill(0, count($rIds), '?'));
            $where[] = "(EXISTS (SELECT 1 FROM tbl_document_access_permissions dap WHERE dap.document_id = d.id AND dap.can_read = 1 AND dap.role_id IN ({$phR}))
                         OR EXISTS (SELECT 1 FROM tbl_document_access_permissions dap2 WHERE dap2.knowledge_base_id = d.knowledge_base_id AND dap2.document_id IS NULL AND dap2.can_read = 1 AND dap2.role_id IN ({$phR})))";
            $binds = array_merge($binds, $rIds, $rIds);
        }

        $st = $this->db->runPrepared(
            "SELECT kc.id AS chunk_id, kc.document_id, kc.chunk_index, kc.section_title, kc.chunk_text, kc.vector_json, kc.embedding_json,
                    d.doc_code, d.title, d.file_name, d.category, kb.kb_code
             FROM tbl_knowledge_chunks kc
             INNER JOIN tbl_knowledge_documents d ON d.id = kc.document_id
             LEFT JOIN tbl_knowledge_bases kb ON kb.id = d.knowledge_base_id
             WHERE " . implode(' AND ', $where) . "
             LIMIT 300",
            $binds
        );
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        if (empty($rows)) return [];

        $qTf = $this->computeTfVector($query);
        $qWords = array_keys($qTf);
        $qEmb = null;
        foreach ($rows as $r) {
            if (!empty($r['embedding_json'])) {
                $respSvc = new ResponseService($this->db);
                if ($respSvc->hasConfiguredKey()) {
                    $qEmb = $respSvc->createEmbedding($query, 'text-embedding-3-small');
                }
                break;
            }
        }

        $scored = [];
        foreach ($rows as $row) {
            $tfVec = json_decode((string)$row['vector_json'], true) ?: [];
            $tfSim = $this->cosineAssoc($qTf, $tfVec);

            $kwBoost = 0.0;
            $haystack = mb_strtolower($row['title'] . ' ' . $row['section_title'] . ' ' . $row['chunk_text'], 'UTF-8');
            foreach ($qWords as $qw) {
                if (strpos($haystack, $qw) !== false) {
                    $kwBoost += 0.08;
                }
            }
            $score = min(1.0, $tfSim + min(0.32, $kwBoost));

            if (is_array($qEmb) && !empty($row['embedding_json'])) {
                $cEmb = json_decode((string)$row['embedding_json'], true);
                if (is_array($cEmb)) {
                    $embSim = $this->cosineDense($qEmb, $cEmb);
                    $score = max($score, ($embSim * 0.78) + ($score * 0.22));
                }
            }

            if ($score >= $minScore) {
                $row['score'] = round($score, 4);
                unset($row['vector_json'], $row['embedding_json']);
                $scored[] = $row;
            }
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $limit);

        if (!empty($top)) {
            $docIds = array_values(array_unique(array_map(static fn($x) => (int)$x['document_id'], $top)));
            $phD = implode(',', array_fill(0, count($docIds), '?'));
            $this->db->runPrepared("UPDATE tbl_knowledge_documents SET retrieval_count = retrieval_count + 1 WHERE id IN ({$phD})", $docIds);
        }

        return $top;
    }

    public function chunkText(string $text, int $maxLen = 520): array {
        $rawParas = preg_split('/\n\s*\n|\r\n\s*\r\n/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $buf = '';
        foreach ($rawParas as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (strlen($buf . "\n" . $p) > $maxLen && $buf !== '') {
                $chunks[] = $buf;
                $buf = $p;
            } else {
                $buf = $buf === '' ? $p : ($buf . "\n" . $p);
            }
        }
        if ($buf !== '') $chunks[] = $buf;
        return !empty($chunks) ? $chunks : [trim($text)];
    }

    public function computeTfVector(string $text): array {
        $lower = mb_strtolower($text, 'UTF-8');
        $words = preg_split('/[^a-z0-9_]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['dan','yang','untuk','dengan','pada','dari','ini','itu','atau','dalam','adalah','the','and','for','with','from','this','that','are','was','apa','what','how','bagaimana'];
        $freq = [];
        foreach ($words as $w) {
            if (strlen($w) >= 3 && !in_array($w, $stop, true)) {
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
        }
        arsort($freq);
        $top = array_slice($freq, 0, 35, true);
        $sumSq = 0.0;
        foreach ($top as $c) $sumSq += $c * $c;
        $norm = sqrt($sumSq) ?: 1.0;
        $vec = [];
        foreach ($top as $k => $c) $vec[$k] = round($c / $norm, 4);
        return $vec;
    }

    private function cosineAssoc(array $a, array $b): float {
        if (empty($a) || empty($b)) return 0.0;
        $dot = 0.0;
        foreach ($a as $k => $v) {
            if (isset($b[$k])) $dot += $v * (float)$b[$k];
        }
        return (float)$dot;
    }

    private function cosineDense(array $a, array $b): float {
        $n = min(count($a), count($b));
        if ($n === 0) return 0.0;
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $va = (float)$a[$i];
            $vb = (float)$b[$i];
            $dot += $va * $vb;
            $na += $va * $va;
            $nb += $vb * $vb;
        }
        $den = sqrt($na) * sqrt($nb);
        return $den > 0 ? ($dot / $den) : 0.0;
    }
}
