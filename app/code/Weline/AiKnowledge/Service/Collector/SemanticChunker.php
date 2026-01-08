<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Service\Collector;

/**
 * Semantic Chunker
 * 
 * Splits text into semantic chunks using a sliding window approach
 * with overlap to preserve context across chunk boundaries.
 * 
 * This is critical for:
 * - API definitions that span multiple lines
 * - Code examples with surrounding context
 * - Permission/authorization checks related to APIs
 */
class SemanticChunker
{
    /**
     * Default chunk size in characters
     */
    private int $chunkSize = 1000;
    
    /**
     * Default overlap size in characters
     */
    private int $overlapSize = 200;
    
    /**
     * Minimum chunk size
     */
    private int $minChunkSize = 100;
    
    /**
     * Configure chunk sizes
     */
    public function configure(int $chunkSize, int $overlapSize, int $minChunkSize = 100): self
    {
        $this->chunkSize = $chunkSize;
        $this->overlapSize = $overlapSize;
        $this->minChunkSize = $minChunkSize;
        return $this;
    }
    
    /**
     * Chunk text into semantic pieces
     * 
     * @param string $text The text to chunk
     * @param array $options Chunking options
     * @return array Array of chunks with metadata
     */
    public function chunk(string $text, array $options = []): array
    {
        $chunkSize = $options['chunk_size'] ?? $this->chunkSize;
        $overlapSize = $options['overlap_size'] ?? $this->overlapSize;
        $minChunkSize = $options['min_chunk_size'] ?? $this->minChunkSize;
        
        if (strlen($text) <= $chunkSize) {
            return [
                [
                    'content' => $text,
                    'start' => 0,
                    'end' => strlen($text),
                    'index' => 0,
                ],
            ];
        }
        
        $chunks = [];
        $position = 0;
        $index = 0;
        $textLength = strlen($text);
        
        while ($position < $textLength) {
            // Calculate end position
            $endPosition = min($position + $chunkSize, $textLength);
            
            // Try to find a good break point
            $breakPosition = $this->findBreakPoint($text, $position, $endPosition);
            
            // Extract chunk
            $chunkContent = substr($text, $position, $breakPosition - $position);
            
            // Skip if too small (unless it's the last chunk)
            if (strlen($chunkContent) < $minChunkSize && $breakPosition < $textLength) {
                $position = $breakPosition;
                continue;
            }
            
            $chunks[] = [
                'content' => trim($chunkContent),
                'start' => $position,
                'end' => $breakPosition,
                'index' => $index,
            ];
            
            $index++;
            
            // Move position with overlap
            $position = max($position + 1, $breakPosition - $overlapSize);
            
            // If we've created too many chunks, break
            if ($index > 1000) {
                break;
            }
        }
        
        return $chunks;
    }
    
    /**
     * Find a good break point for chunking
     * 
     * Prioritizes breaking at:
     * 1. Paragraph breaks (double newline)
     * 2. Single newlines
     * 3. Sentence endings (. ! ?)
     * 4. Word boundaries (spaces)
     */
    private function findBreakPoint(string $text, int $start, int $end): int
    {
        $segment = substr($text, $start, $end - $start);
        $segmentLength = strlen($segment);
        
        // Look back from the end for a good break point
        $searchStart = max(0, $segmentLength - 200); // Look in the last 200 chars
        $searchSegment = substr($segment, $searchStart);
        
        // Try paragraph break first
        $lastParagraph = strrpos($searchSegment, "\n\n");
        if ($lastParagraph !== false) {
            return $start + $searchStart + $lastParagraph + 2;
        }
        
        // Try heading break (markdown)
        $lastHeading = strrpos($searchSegment, "\n#");
        if ($lastHeading !== false) {
            return $start + $searchStart + $lastHeading + 1;
        }
        
        // Try single newline
        $lastNewline = strrpos($searchSegment, "\n");
        if ($lastNewline !== false) {
            return $start + $searchStart + $lastNewline + 1;
        }
        
        // Try sentence ending
        foreach (['. ', '! ', '? ', ".\n", "!\n", "?\n"] as $ending) {
            $lastSentence = strrpos($searchSegment, $ending);
            if ($lastSentence !== false) {
                return $start + $searchStart + $lastSentence + strlen($ending);
            }
        }
        
        // Try word boundary
        $lastSpace = strrpos($searchSegment, ' ');
        if ($lastSpace !== false) {
            return $start + $searchStart + $lastSpace + 1;
        }
        
        // Fall back to exact position
        return $end;
    }
    
    /**
     * Chunk a document preserving structure
     * 
     * For structured documents (like API docs), this method
     * tries to keep related sections together.
     * 
     * @param string $text The document text
     * @param array $options Chunking options
     * @return array Array of chunks with metadata
     */
    public function chunkDocument(string $text, array $options = []): array
    {
        // First, try to identify sections
        $sections = $this->identifySections($text);
        
        if (empty($sections)) {
            return $this->chunk($text, $options);
        }
        
        $chunks = [];
        $index = 0;
        
        foreach ($sections as $section) {
            $sectionChunks = $this->chunk($section['content'], $options);
            
            foreach ($sectionChunks as $chunk) {
                $chunk['index'] = $index++;
                $chunk['section'] = $section['title'];
                $chunk['start'] += $section['start'];
                $chunk['end'] += $section['start'];
                $chunks[] = $chunk;
            }
        }
        
        return $chunks;
    }
    
    /**
     * Identify sections in a document
     * 
     * Looks for markdown headers to identify sections.
     */
    private function identifySections(string $text): array
    {
        $sections = [];
        $pattern = '/^(#{1,3})\s+(.+)$/m';
        
        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        
        $positions = [];
        foreach ($matches[0] as $i => $match) {
            $positions[] = [
                'title' => $matches[2][$i][0],
                'level' => strlen($matches[1][$i][0]),
                'start' => $match[1],
            ];
        }
        
        // Add end positions
        for ($i = 0; $i < count($positions); $i++) {
            $start = $positions[$i]['start'];
            $end = isset($positions[$i + 1]) ? $positions[$i + 1]['start'] : strlen($text);
            
            $sections[] = [
                'title' => $positions[$i]['title'],
                'level' => $positions[$i]['level'],
                'start' => $start,
                'end' => $end,
                'content' => substr($text, $start, $end - $start),
            ];
        }
        
        return $sections;
    }
    
    /**
     * Chunk code with syntax awareness
     * 
     * For PHP code, tries to keep function/method definitions intact.
     */
    public function chunkCode(string $code, string $language = 'php', array $options = []): array
    {
        if ($language !== 'php') {
            return $this->chunk($code, $options);
        }
        
        $chunks = [];
        $index = 0;
        
        // Try to identify function/method boundaries
        $pattern = '/(?:public|protected|private|static|\s)+function\s+\w+\s*\([^)]*\)[^{]*\{/m';
        
        if (!preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
            return $this->chunk($code, $options);
        }
        
        $positions = array_column($matches[0], 1);
        $positions = array_merge([0], $positions, [strlen($code)]);
        
        for ($i = 0; $i < count($positions) - 1; $i++) {
            $start = $positions[$i];
            $end = $positions[$i + 1];
            $content = substr($code, $start, $end - $start);
            
            // If the chunk is too large, split it further
            if (strlen($content) > ($options['chunk_size'] ?? $this->chunkSize) * 2) {
                $subChunks = $this->chunk($content, $options);
                foreach ($subChunks as $subChunk) {
                    $subChunk['index'] = $index++;
                    $subChunk['start'] += $start;
                    $subChunk['end'] += $start;
                    $chunks[] = $subChunk;
                }
            } else {
                $chunks[] = [
                    'content' => trim($content),
                    'start' => $start,
                    'end' => $end,
                    'index' => $index++,
                ];
            }
        }
        
        return $chunks;
    }
}
