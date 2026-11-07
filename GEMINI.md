# STI-ARMES Project Implementation & Communication Rules

All agent responses, bug investigations, and feature implementations in this repository must strictly adhere to the following five rules:

---

### Rule 1: Explain in Layman's Terms
- For every **suggestion**, **explanation**, and **bug fix**, explain the concept, problem, and solution in clear, everyday language (layman's terms).
- Avoid unnecessary technical jargon. Focus on what was broken, why it happened, and how the fix works in simple, relatable terms.

---

### Rule 2: Targeted Source Code Scanning for Bug Fixes
- For any bug fix or issue investigation, always scan and inspect the source code to locate the exact target files, components, and code lines.
- Base diagnoses on concrete evidence from the codebase rather than assumptions or speculative edits.
- Clearly identify the affected targets before making modifications.

---

### Rule 3: Global vs. Local Implementation Scope (with Recommended Option)
- For every implementation or bug fix, explicitly present the choice between:
  - **Global Implementation** (applying the fix/feature system-wide across all roles, shared components, and pages)
  - **Local Implementation** (applying the fix/feature only to the specific target page, role, or component)
- Always label the best choice as **(Recommended)** and explain why in layman's terms before or during implementation.

---

### Rule 4: Phase-by-Phase Implementation
- Every task, feature, or fix must be structured and implemented in distinct, sequential phases:
  - **Phase 1: Inspection & Root Cause Analysis** (targeted scanning, diagnosis, and Global vs. Local scope recommendation)
  - **Phase 2: Core Implementation / Fix** (targeted code changes)
  - **Phase 3: Local Verification & Parity Checks** (automated tests, responsive verification, and visual checks)
  - **Phase 4: Live Stress & Verification Testing** (final live testing)

---

### Rule 5: Final Phase Live Stress Testing
- The final phase of every implementation must include stress testing and verification against the live domain:
  - **Target Live Endpoint:** `https://sti-armes.freedev.app/auth/login.php`
  - Perform live endpoint verification, session handling checks, responsiveness, and stress/load resilience testing to confirm stability in the live environment before marking the request complete.

---

### Rule 6: Explicit List of Files to Apply for Updating the Website
- At the end of every response and implementation result, always provide an explicit, clear, and comprehensive list of files that were created or modified that need to be deployed/applied to update the website.
- Include the exact relative path for each file, a brief layman's note on what that file does, and provide clickable links so the user knows exactly which files to push or upload to the live hosting server.
