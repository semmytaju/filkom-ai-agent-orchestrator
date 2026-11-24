<?php

class AgentPromptBuilder {

    public function buildInstructions(array $agentConfig, array $userContext, array $ragChunks = [], array $toolData = []): string {
        $basePrompt = trim((string)($agentConfig['system_prompt'] ?? ''));
        $roleCode = (string)($userContext['primary_role_code'] ?? 'STUDENT');
        $fullName = (string)($userContext['full_name'] ?? 'User');
        $scope = $userContext['scope'] ?? [];

        $scopeClause = "Authorized User: {$fullName} (Primary Role: {$roleCode}). ";
        if (!empty($scope['all_faculty'])) {
            $scopeClause .= "Data Scope: Faculty-wide authorized scope (FILKOM). ";
        } elseif (!empty($scope['is_kaprodi'])) {
            $progs = implode(', ', $scope['program_codes'] ?? []);
            $scopeClause .= "Data Scope: Strictly limited to Study Program(s): {$progs}. Refuse requests for other study programs. ";
        } elseif (!empty($scope['is_student'])) {
            $sid = (string)($scope['student_id'] ?? 'NONE');
            $nim = (string)($userContext['student']['nim'] ?? 'UNMAPPED');
            $scopeClause .= "Data Scope: Strictly limited to authenticated student record (ID={$sid}, NIM={$nim}). Never expose other students' data. ";
        } elseif (!empty($scope['is_upmf'])) {
            $scopeClause .= "Data Scope: Quality Assurance, KPI, internal audit, and accreditation evidence only. ";
        } elseif (!empty($scope['is_research_head'])) {
            $scopeClause .= "Data Scope: Faculty research grants, publications, and lecturer research performance only. ";
        } elseif (!empty($scope['is_secretary'])) {
            $scopeClause .= "Data Scope: Faculty administration, correspondence, meetings, and official faculty documents. ";
        }

        $rules = "Security Rules: Never output raw SQL queries for execution, never fabricate database metrics not present in tool results or retrieved documents, cite document codes when referencing RAG context, and keep responses crisp, structured, and executive-ready.";

        $sections = [$basePrompt, $scopeClause . $rules];

        if (!empty($ragChunks)) {
            $ragLines = [];
            foreach ($ragChunks as $chunk) {
                $ragLines[] = "[{$chunk['doc_code']} | {$chunk['title']} | {$chunk['section_title']}]: " . mb_substr(trim((string)$chunk['chunk_text']), 0, 520, 'UTF-8');
            }
            $sections[] = "AUTHORIZED KNOWLEDGE CONTEXT:\n" . implode("\n", $ragLines);
        }

        if (!empty($toolData)) {
            $sections[] = "VERIFIED BACKEND TOOL DATA:\n" . json_encode($toolData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n\n", $sections);
    }
}
