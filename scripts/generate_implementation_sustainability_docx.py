import docx
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_ALIGN_VERTICAL
from docx.oxml import parse_xml, OxmlElement
from docx.oxml.ns import nsdecls, qn
import os

def create_document():
    doc = docx.Document()

    # Page Margins (1 inch everywhere)
    for section in doc.sections:
        section.top_margin = Inches(1.0)
        section.bottom_margin = Inches(1.0)
        section.left_margin = Inches(1.0)
        section.right_margin = Inches(1.0)
        
        # Header / Footer
        header = section.header
        hp = header.paragraphs[0]
        hp.alignment = WD_ALIGN_PARAGRAPH.RIGHT
        hrun = hp.add_run("STI-ARMES | Implementation & 3-Year Sustainability Plan")
        hrun.font.name = "Calibri"
        hrun.font.size = Pt(8.5)
        hrun.font.color.rgb = RGBColor(148, 163, 184)

        footer = section.footer
        fp = footer.paragraphs[0]
        fp.alignment = WD_ALIGN_PARAGRAPH.CENTER
        frun = fp.add_run("STI College Marikina — Activity Request, Monitoring, and Evaluation System")
        frun.font.name = "Calibri"
        frun.font.size = Pt(8.5)
        frun.font.color.rgb = RGBColor(148, 163, 184)

    # Styles & Colors
    # Primary STI Blue: #003876 (0, 56, 118)
    # Secondary Gold/Yellow: #E5A823
    # Dark Text: #1E293B (30, 41, 59)
    # Muted: #64748B (100, 116, 139)
    
    # Document Header / Branding
    p_brand = doc.add_paragraph()
    p_brand.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_brand.paragraph_format.space_after = Pt(2)
    run_brand = p_brand.add_run("STI COLLEGE MARIKINA")
    run_brand.font.name = "Calibri"
    run_brand.font.size = Pt(13)
    run_brand.font.bold = True
    run_brand.font.color.rgb = RGBColor(0, 56, 118)

    p_subbrand = doc.add_paragraph()
    p_subbrand.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_subbrand.paragraph_format.space_after = Pt(14)
    run_sub = p_subbrand.add_run("ACTIVITY REQUEST, MONITORING, AND EVALUATION SYSTEM (STI-ARMES)")
    run_sub.font.name = "Calibri"
    run_sub.font.size = Pt(10)
    run_sub.font.color.rgb = RGBColor(100, 116, 139)

    # Title Box
    p_title = doc.add_paragraph()
    p_title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_title.paragraph_format.space_after = Pt(4)
    run_title = p_title.add_run("PROJECT IMPLEMENTATION & 3-YEAR SUSTAINABILITY PLAN")
    run_title.font.name = "Calibri"
    run_title.font.size = Pt(18)
    run_title.font.bold = True
    run_title.font.color.rgb = RGBColor(0, 56, 118)

    p_meta = doc.add_paragraph()
    p_meta.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p_meta.paragraph_format.space_after = Pt(20)
    run_meta = p_meta.add_run("Capstone Defense Panel Governance Deliverables — Panel Requirements I.2 & I.3")
    run_meta.font.name = "Calibri"
    run_meta.font.size = Pt(10.5)
    run_meta.font.italic = True
    run_meta.font.color.rgb = RGBColor(71, 85, 105)

    # Divider line
    p_div = doc.add_paragraph()
    p_div.paragraph_format.space_after = Pt(16)
    p_div_run = p_div.add_run("—" * 58)
    p_div_run.font.color.rgb = RGBColor(203, 213, 225)
    p_div.alignment = WD_ALIGN_PARAGRAPH.CENTER

    # SECTION 1: IMPLEMENTATION PLAN
    h1 = doc.add_heading(level=1)
    h1.paragraph_format.space_before = Pt(10)
    h1.paragraph_format.space_after = Pt(6)
    h1_run = h1.add_run("1. Phased Implementation Plan (Panel Requirement I.2)")
    h1_run.font.name = "Calibri"
    h1_run.font.size = Pt(14)
    h1_run.font.bold = True
    h1_run.font.color.rgb = RGBColor(0, 56, 118)

    p_intro1 = doc.add_paragraph()
    p_intro1.paragraph_format.space_after = Pt(10)
    p_intro1.paragraph_format.line_spacing = 1.15
    run_intro1 = p_intro1.add_run(
        "The implementation plan outlines the structured, 4-phase rollout of STI-ARMES across STI College Marikina. "
        "The objective is to achieve a seamless, zero-disruption transition from legacy paper-based activity forms to a fully "
        "digital, automated, and audited activity management system."
    )
    run_intro1.font.name = "Calibri"
    run_intro1.font.size = Pt(10.5)
    run_intro1.font.color.rgb = RGBColor(30, 41, 59)

    # Table: Phased Implementation Plan
    table1 = doc.add_table(rows=1, cols=4)
    table1.alignment = WD_TABLE_ALIGNMENT.CENTER
    table1.autofit = False

    # Widths
    col_widths1 = [Inches(1.5), Inches(1.1), Inches(2.5), Inches(1.4)]

    # Headers
    headers1 = ["Phase", "Timeline", "Key Milestones & Activities", "Responsible Stakeholders"]
    hdr_cells1 = table1.rows[0].cells
    for i, h_text in enumerate(headers1):
        hdr_cells1[i].text = h_text
        hdr_cells1[i].width = col_widths1[i]
        # Shading
        shading = parse_xml(r'<w:shd {} w:fill="003876"/>'.format(nsdecls('w')))
        hdr_cells1[i]._tc.get_or_add_tcPr().append(shading)
        p = hdr_cells1[i].paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        for r in p.runs:
            r.font.name = "Calibri"
            r.font.size = Pt(9.5)
            r.font.bold = True
            r.font.color.rgb = RGBColor(255, 255, 255)

    # Data Rows
    phases_data = [
        (
            "Phase 1: Environment & Database Provisioning",
            "Weeks 1 – 2",
            "• Deploy STI-ARMES source code to the production server.\n"
            "• Initialize MySQL database schema with self-healing tables.\n"
            "• Populate institutional lookup data (Academic Terms, Departments, and Accredited Student Organizations).\n"
            "• Configure SMTP notification credentials and OneDrive backup links.",
            "Capstone Proponents, IT Program Head"
        ),
        (
            "Phase 2: Role-Based Onboarding & Training",
            "Weeks 3 – 4",
            "• Conduct hands-on orientation workshops for Faculty Proponents on digital proposal creation and AI rationale drafting.\n"
            "• Train Program Heads, Student Org Head, and Events Committee Head on multi-tier review workflows.\n"
            "• Train the Academic Head / Dean on clearance validation, KPI oversight, and final approvals.\n"
            "• Distribute quick-reference user manuals and video walkthroughs.",
            "Capstone Proponents, Events Committee, Academic Head"
        ),
        (
            "Phase 3: Parallel Pilot Run",
            "Month 2",
            "• Execute digital activity proposals concurrently with existing paper forms across all academic departments.\n"
            "• Verify automated venue conflict detection in real campus scheduling.\n"
            "• Pilot digital participant evaluations via dynamic QR codes and integrated Google Forms.\n"
            "• Collect user feedback and fine-tune operational parameters.",
            "All Departments (IT, THM, G.E.), Student Organizations, Proponents"
        ),
        (
            "Phase 4: Full Institutional Cutover",
            "Month 3 Onward",
            "• Decommission legacy paper proposal forms and switch 100% paperless to STI-ARMES.\n"
            "• Enforce mandatory digital equipment return and damage reporting.\n"
            "• Require digital budget breakdown & expense liquidation uploads.\n"
            "• Auto-generate AI-assisted post-event assessment reports for institutional KPI tracking.",
            "Academic Head (Dean), Events Committee, Department Heads"
        )
    ]

    for row_idx, data in enumerate(phases_data):
        row_cells = table1.add_row().cells
        for col_idx, text in enumerate(data):
            row_cells[col_idx].text = text
            row_cells[col_idx].width = col_widths1[col_idx]
            # Alternating row background
            fill_color = "F8FAFC" if row_idx % 2 == 1 else "FFFFFF"
            shd = parse_xml(r'<w:shd {} w:fill="{}"/>'.format(nsdecls('w'), fill_color))
            row_cells[col_idx]._tc.get_or_add_tcPr().append(shd)
            p = row_cells[col_idx].paragraphs[0]
            p.paragraph_format.line_spacing = 1.15
            for r in p.runs:
                r.font.name = "Calibri"
                r.font.size = Pt(9.0)
                r.font.color.rgb = RGBColor(30, 41, 59)
                if col_idx == 0:
                    r.font.bold = True
                elif col_idx == 1:
                    r.font.bold = True
                    r.font.color.rgb = RGBColor(0, 56, 118)

    # Cell padding for Table 1
    for row in table1.rows:
        for cell in row.cells:
            tcPr = cell._tc.get_or_add_tcPr()
            tcMar = OxmlElement('w:tcMar')
            for margin in ['top', 'bottom']:
                node = OxmlElement(f'w:{margin}')
                node.set(qn('w:w'), '120')
                node.set(qn('w:type'), 'dxa')
                tcMar.append(node)
            for margin in ['left', 'right']:
                node = OxmlElement(f'w:{margin}')
                node.set(qn('w:w'), '140')
                node.set(qn('w:type'), 'dxa')
                tcMar.append(node)
            tcPr.append(tcMar)

    # SECTION 2: 3-YEAR SUSTAINABILITY PLAN
    doc.add_page_break()

    h2 = doc.add_heading(level=1)
    h2.paragraph_format.space_before = Pt(10)
    h2.paragraph_format.space_after = Pt(6)
    h2_run = h2.add_run("2. Three-Year (3-Year) Sustainability Plan (Panel Requirement I.3)")
    h2_run.font.name = "Calibri"
    h2_run.font.size = Pt(14)
    h2_run.font.bold = True
    h2_run.font.color.rgb = RGBColor(0, 56, 118)

    p_intro2 = doc.add_paragraph()
    p_intro2.paragraph_format.space_after = Pt(12)
    p_intro2.paragraph_format.line_spacing = 1.15
    run_intro2 = p_intro2.add_run(
        "The sustainability plan guarantees that STI-ARMES remains technically resilient, financially cost-free, "
        "and institutionally maintained long after the initial capstone proponents graduate. It provides a strategic, year-by-year roadmap "
        "covering technical, financial, and operational continuity."
    )
    run_intro2.font.name = "Calibri"
    run_intro2.font.size = Pt(10.5)
    run_intro2.font.color.rgb = RGBColor(30, 41, 59)

    years_data = [
        {
            "year": "YEAR 1: Stabilization & Automated Self-Healing Operations",
            "theme": "Focus: Zero-Cost Cloud Deployment, Automated Integrity Checks, and Initial Campus Adoption",
            "points": [
                ("Financial Feasibility (₱0.00 Cost):", "The system operates completely free of charge. Developed with open-source technologies (PHP 8, MariaDB/MySQL, Vanilla HTML5/CSS3/JavaScript) with zero proprietary runtime licensing fees. Hosting is maintained on the existing campus intranet or developer cloud environment."),
                ("Self-Healing Technical Architecture:", "Built-in database integrity checks (configured in includes/auth.php) automatically detect and repair missing schema columns or tables during administrative login, preventing downtime from schema divergence."),
                ("Deterministic AI Fallbacks:", "AI-assisted modules (rationale drafting, objective synthesis, and post-event assessment summaries) feature internal algorithmic fallback templates, ensuring 100% system availability even during external API downtime or network fluctuations."),
                ("Automated Backup Protocol:", "Bi-weekly scheduled database dumps are exported through the System Administration portal (dean/system-admin.php) with cloud sync support to prevent any data loss.")
            ]
        },
        {
            "year": "YEAR 2: Departmental Stewardship & Student Practicum Handover",
            "theme": "Focus: Institutional Turnover, Academic OJT Maintenance Cycle, and Annual Lookup Updates",
            "points": [
                ("Institutional Turnover to IT Department:", "Under the formal Memorandum of Understanding (MOU), full source code repositories, database access, and server credentials are officially handed over to the IT Department under the direct supervision of the IT Program Head."),
                ("BSIT Senior Practicum / Capstone Maintenance Track:", "Routine maintenance, UI enhancements, and feature patches are officially integrated into the annual BSIT Senior Practicum / OJT curriculum. Graduating students gain practical experience maintaining an active production system at zero labor cost to the school."),
                ("Administrative Lookup Maintenance:", "At the beginning of each academic year, the IT Department updates semester schedules, active academic terms, and newly accredited student organizations through the System Administration panel."),
                ("Permanent Campus Hosting Migration:", "Should external developer hosting conclude, the system transitions smoothly to STI College Marikina's existing campus local server hardware or intranet, eliminating all external hosting expenses permanently.")
            ]
        },
        {
            "year": "YEAR 3: Longitudinal Analytics & Institutional Archiving",
            "theme": "Focus: Multi-Year KPI Comparisons, Historical Data Archival, and Strategic Decision-Making",
            "points": [
                ("Automated Academic Archiving:", "Completed academic year proposals, participant attendance records, and evaluation datasets are archived using the built-in SQL/CSV archival tool in dean/system-admin.php, ensuring fast query performance for active school years."),
                ("Multi-Year Institutional KPI Analytics:", "The Academic Head and Dean utilize cumulative 3-year data to analyze long-term trends in department budget utilization, approval turnaround times, and student event satisfaction scores."),
                ("Policy and Activity Optimization:", "Longitudinal insights guide the Academic Committee in refining future activity guidelines, budget allocations, and institutional key performance indicators (KPIs)."),
                ("Permanent Institutional Asset:", "STI-ARMES is fully cemented as an integral institutional asset of STI College Marikina, serving as an operational benchmark for digital campus governance.")
            ]
        }
    ]

    for y in years_data:
        # Year Header Box
        p_yh = doc.add_paragraph()
        p_yh.paragraph_format.space_before = Pt(14)
        p_yh.paragraph_format.space_after = Pt(2)
        y_run = p_yh.add_run(y["year"])
        y_run.font.name = "Calibri"
        y_run.font.size = Pt(12)
        y_run.font.bold = True
        y_run.font.color.rgb = RGBColor(0, 56, 118)

        p_yt = doc.add_paragraph()
        p_yt.paragraph_format.space_after = Pt(8)
        yt_run = p_yt.add_run(y["theme"])
        yt_run.font.name = "Calibri"
        yt_run.font.size = Pt(9.5)
        yt_run.font.italic = True
        yt_run.font.color.rgb = RGBColor(100, 116, 139)

        for title, desc in y["points"]:
            p_pt = doc.add_paragraph(style='List Bullet')
            p_pt.paragraph_format.space_after = Pt(4)
            p_pt.paragraph_format.line_spacing = 1.15
            run_pt_title = p_pt.add_run(f"{title} ")
            run_pt_title.font.name = "Calibri"
            run_pt_title.font.size = Pt(10)
            run_pt_title.font.bold = True
            run_pt_title.font.color.rgb = RGBColor(30, 41, 59)

            run_pt_desc = p_pt.add_run(desc)
            run_pt_desc.font.name = "Calibri"
            run_pt_desc.font.size = Pt(10)
            run_pt_desc.font.color.rgb = RGBColor(71, 85, 105)

    # SECTION 3: INSTITUTIONAL SIGN-OFF & ACKNOWLEDGMENT
    p_div2 = doc.add_paragraph()
    p_div2.paragraph_format.space_before = Pt(16)
    p_div2.paragraph_format.space_after = Pt(14)
    p_div2_run = p_div2.add_run("—" * 58)
    p_div2_run.font.color.rgb = RGBColor(203, 213, 225)
    p_div2.alignment = WD_ALIGN_PARAGRAPH.CENTER

    h3 = doc.add_heading(level=2)
    h3.paragraph_format.space_after = Pt(8)
    h3_run = h3.add_run("3. Governance Sign-Off & Institutional Endorsement")
    h3_run.font.name = "Calibri"
    h3_run.font.size = Pt(12)
    h3_run.font.bold = True
    h3_run.font.color.rgb = RGBColor(0, 56, 118)

    p_ack = doc.add_paragraph()
    p_ack.paragraph_format.space_after = Pt(18)
    p_ack_run = p_ack.add_run(
        "By signing below, the Capstone Proponents and STI College Marikina Academic & Administrative Authorities "
        "confirm agreement with the phased implementation timeline and 3-year sustainability governance model."
    )
    p_ack_run.font.name = "Calibri"
    p_ack_run.font.size = Pt(9.5)
    p_ack_run.font.italic = True
    p_ack_run.font.color.rgb = RGBColor(100, 116, 139)

    # Signatures Table (2x2 grid)
    sig_table = doc.add_table(rows=2, cols=2)
    sig_table.alignment = WD_TABLE_ALIGNMENT.CENTER
    sig_table.autofit = False

    sig_widths = [Inches(3.2), Inches(3.2)]
    signatures = [
        ("CAPSTONE PROPONENTS", "Developers / Proponents\nBS Information Technology\nDate: ________________________"),
        ("SIR IAN JADE BARANGAN", "Events Committee Head\nSTI College Marikina\nDate: ________________________"),
        ("SIR DAVE LORD RUBAYA", "IT Program Head\nSTI College Marikina\nDate: ________________________"),
        ("DEAN FREDERIC YULO", "Academic Head / Dean\nSTI College Marikina\nDate: ________________________")
    ]

    for idx, (name, role) in enumerate(signatures):
        row_i = idx // 2
        col_i = idx % 2
        cell = sig_table.rows[row_i].cells[col_i]
        cell.width = sig_widths[col_i]
        p = cell.paragraphs[0]
        p.paragraph_format.space_before = Pt(8)
        p.paragraph_format.space_after = Pt(16)
        
        run_line = p.add_run("_________________________________________\n")
        run_line.font.bold = True
        run_line.font.color.rgb = RGBColor(148, 163, 184)

        run_n = p.add_run(name + "\n")
        run_n.font.name = "Calibri"
        run_n.font.size = Pt(10)
        run_n.font.bold = True
        run_n.font.color.rgb = RGBColor(0, 56, 118)

        run_r = p.add_run(role)
        run_r.font.name = "Calibri"
        run_r.font.size = Pt(8.5)
        run_r.font.color.rgb = RGBColor(100, 116, 139)

    # Save document
    output_dir = r"C:\xampp\htdocs\sti-activity-system\docs"
    os.makedirs(output_dir, exist_ok=True)
    out_path = os.path.join(output_dir, "STI_ARMES_Implementation_and_Sustainability_Plan.docx")
    doc.save(out_path)
    print(f"Successfully generated Word document at: {out_path}")

if __name__ == "__main__":
    create_document()
