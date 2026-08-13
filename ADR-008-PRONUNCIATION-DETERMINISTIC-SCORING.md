# ADR-008: Pronunciation Analysis & Deterministic Scoring Architecture

## Status
ACCEPTED

## Date
2026-08-13

## Context
[Why we need this decision — Phase ⑥ pronunciation evaluation for Shadowing module]

## Decision

### Core Principles
1. LLM is NOT the pronunciation judge
2. Speech model extracts evidence (transcript, timestamps, confidence, phonemes)
3. Alignment engine compares student vs target transcript
4. Fixed scoring engine produces score deterministically
5. Same evidence produces same score — always
6. LLM is optional coaching/explanation only — cannot alter scores
7. Recording success never creates fake/placeholder score
8. Final scoring weights are TBD until Product Owner approval

### Architecture Components
[Document the pipeline: Provider → Adapter → Analysis Service → Alignment → Error Detection → Scoring → Result → UI]

### Responsibility Split
- Speech Model/Provider: listens, analyzes, extracts evidence. Does NOT decide score.
- Alignment Engine: compares canonical transcript vs student recognized output. Produces: EXACT/WRONG/NEAR/DELETION/INSERTION
- Pronunciation Error Engine: identifies wrong word, omitted word, inserted word, phoneme mismatch, timing issues
- Deterministic Scoring Engine: calculates final 0-100 score by fixed rules

### UI Target (Future)
- Word chips: 🟢 Correct, 🟡 Near/minor, 🔴 Wrong/retry
- Detailed phoneme mismatch rows
- Deterministic score display
- Do NOT build UI yet — document target only

### LLM Coach (Optional, Future)
- Can explain pronunciation errors in natural language
- MUST NOT calculate, change, or override scores
- MUST NOT change alignment or pronunciation evidence
- MUST NOT mark mastery
- Core scoring works without LLM
- LLM coaching is asynchronous — don't block UI on it

### Provider Abstraction
- Use `PronunciationAnalysisProviderContract`
- Domain must NOT hardcode: Deepgram, Azure, Google, Whisper
- Provider output normalized into project-owned schema

## Out of Scope
- Final provider selection
- Deepgram/Azure implementation
- Phoneme scoring implementation
- LLM coaching implementation
- Final scoring weight definition
- Mastery logic changes
- Fake pronunciation samples
- Recording PR core modifications

## Consequences
- Pronunciation scores will be reproducible and testable
- Provider can be swapped without changing scoring logic
- LLM costs remain optional, not on critical path
- Scoring formula can be tuned independently of provider
