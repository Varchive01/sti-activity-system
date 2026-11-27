import os
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_ALIGN_VERTICAL
from docx.oxml import OxmlElement, parse_xml
from docx.oxml.ns import nsdecls, qn

def set_cell_background(cell, hex_color):
    shading_elm = parse_xml(f'<w:shd {nsdecls("w")} w:fill="{hex_color}"/>')
    cell._tc.get_or_add_tcPr().append(shading_elm)

def set_cell_margins(cell, top=100, bottom=100, left=150, right=150):
    tcPr = cell._tc.get_or_add_tcPr()
    tcMar = parse_xml(f'<w:tcMar {nsdecls("w")}><w:top w:w="{top}" w:type="dxa"/><w:bottom w:w="{bottom}" w:type="dxa"/><w:left w:w="{left}" w:type="dxa"/><w:right w:w="{right}" w:type="dxa"/></w:tcMar>')
    tcPr.append(tcMar)

def set_table_borders(table, color="D3D3D3", sz="4", val="single"):
    tblPr = table._tbl.tblPr
    borders = parse_xml(
        f'<w:tblBorders {nsdecls("w")}>'
        f'<w:top w:val="{val}" w:sz="{sz}" w:space="0" w:color="{color}"/>'
        f'<w:bottom w:val="{val}" w:sz="{sz}" w:space="0" w:color="{color}"/>'
        f'<w:left w:val="none"/>'
        f'<w:right w:val="none"/>'
        f'<w:insideH w:val="{val}" w:sz="{sz}" w:space="0" w:color="{color}"/>'
        f'<w:insideV w:val="{val}" w:sz="{sz}" w:space="0" w:color="{color}"/>'
        f'</w:tblBorders>'
    )
    tblPr.append(borders)

def build_docx(output_path):
    doc = Document()

    # Set page margins to 0.75 in
    for section in doc.sections:
        section.top_margin = Inches(0.7)
        section.bottom_margin = Inches(0.7)
        section.left_margin = Inches(0.75)
        section.right_margin = Inches(0.75)

    # Styles
    sti_blue = RGBColor(0, 56, 118)      # #003876
    sti_yellow = RGBColor(255, 199, 44)  # #FFC72C
    dark_gray = RGBColor(40, 40, 40)
    muted_gray = RGBColor(100, 100, 100)

    # Title Banner
    p_title = doc.add_paragraph()
    p_title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run_inst = p_title.add_run("STI COLLEGE MARIKINA\n")
    run_inst.font.name = "Arial"
    run_inst.font.size = Pt(13)
    run_inst.font.bold = True
    run_inst.font.color.rgb = sti_blue

    run_sub = p_title.add_run("User Acceptance Testing (UAT) Questionnaire\n")
    run_sub.font.name = "Arial"
    run_sub.font.size = Pt(15)
    run_sub.font.bold = True
    run_sub.font.color.rgb = sti_blue

    run_app = p_title.add_run("Web-Based Activity Request, Monitoring, and Evaluation System (STI-ARMES)\n")
    run_app.font.name = "Arial"
    run_app.font.size = Pt(11)
    run_app.font.bold = True
    run_app.font.color.rgb = dark_gray

    run_role = p_title.add_run("Evaluation Instrument for Faculty Proponents")
    run_role.font.name = "Arial"
    run_role.font.size = Pt(10.5)
    run_role.font.italic = True
    run_role.font.color.rgb = muted_gray

    p_div = doc.add_paragraph()
    p_div.paragraph_format.space_before = Pt(2)
    p_div.paragraph_format.space_after = Pt(8)
    run_div = p_div.add_run("—" * 58)
    run_div.font.color.rgb = sti_yellow
    p_div.alignment = WD_ALIGN_PARAGRAPH.CENTER

    # Respondent Details Box
    table_meta = doc.add_table(rows=3, cols=2)
    table_meta.alignment = WD_TABLE_ALIGNMENT.CENTER
    table_meta.autofit = False

    meta_widths = [Inches(1.8), Inches(5.2)]
    for row in table_meta.rows:
        for idx, width in enumerate(meta_widths):
            row.cells[idx].width = width

    cells = table_meta.rows[0].cells
    cells[0].paragraphs[0].add_run("Faculty Name:").font.bold = True
    cells[1].paragraphs[0].add_run("_________________________________________________________")

    cells = table_meta.rows[1].cells
    cells[0].paragraphs[0].add_run("Department / Program:").font.bold = True
    cells[1].paragraphs[0].add_run("[   ] Information Technology (IT) / Computer Science\n[   ] Tourism & Hospitality Management (THM)\n[   ] Business Administration / Accountancy\n[   ] Senior High School (SHS)")

    cells = table_meta.rows[2].cells
    cells[0].paragraphs[0].add_run("Date of Evaluation:").font.bold = True
    cells[1].paragraphs[0].add_run("_________________________________________________________")

    set_table_borders(table_meta, color="CCCCCC", sz="4")
    for row in table_meta.rows:
        for cell in row.cells:
            set_cell_margins(cell, top=80, bottom=80, left=100, right=100)

    # Instructions Paragraph
    p_inst = doc.add_paragraph()
    p_inst.paragraph_format.space_before = Pt(10)
    p_inst.paragraph_format.space_after = Pt(4)
    run_inst_head = p_inst.add_run("Instructions: ")
    run_inst_head.font.bold = True
    run_inst_head.font.size = Pt(10)
    run_inst_body = p_inst.add_run("Please read each statement carefully. Indicate your assessment by placing a checkmark (✓) in the column that best corresponds to your level of agreement based on your actual experience using the features included in the system.")
    run_inst_body.font.size = Pt(9.5)

    # Rating Scale Benchmark
    p_scale_title = doc.add_paragraph()
    p_scale_title.paragraph_format.space_before = Pt(4)
    p_scale_title.paragraph_format.space_after = Pt(2)
    run_sc_title = p_scale_title.add_run("Rating Scale & Descriptive Benchmarks")
    run_sc_title.font.bold = True
    run_sc_title.font.size = Pt(10)
    run_sc_title.font.color.rgb = sti_blue

    table_scale = doc.add_table(rows=5, cols=4)
    table_scale.alignment = WD_TABLE_ALIGNMENT.CENTER
    set_table_borders(table_scale, color="B0C4DE", sz="6")

    scale_headers = ["Rating", "Scale", "Verbal Interpretation", "Descriptive Benchmark"]
    scale_widths = [Inches(0.8), Inches(1.3), Inches(1.7), Inches(3.2)]

    hdr_cells = table_scale.rows[0].cells
    for i, title in enumerate(scale_headers):
        hdr_cells[i].width = scale_widths[i]
        set_cell_background(hdr_cells[i], "003876")
        set_cell_margins(hdr_cells[i], top=100, bottom=100, left=100, right=100)
        p = hdr_cells[i].paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER if i < 3 else WD_ALIGN_PARAGRAPH.LEFT
        run = p.add_run(title)
        run.font.bold = True
        run.font.size = Pt(9)
        run.font.color.rgb = RGBColor(255, 255, 255)

    scale_data = [
        ("4", "Strongly Agree", "Highly Acceptable", "Feature performs flawlessly; fully meets and exceeds faculty expectations."),
        ("3", "Agree", "Acceptable", "Feature meets operational requirements with minimal or negligible friction."),
        ("2", "Disagree", "Needs Improvement", "Feature exhibits notable friction, gaps, or difficulties during use."),
        ("1", "Strongly Disagree", "Unacceptable", "Feature fails to function properly or prevents task completion.")
    ]

    for r_idx, (r, s, vi, db) in enumerate(scale_data, start=1):
        row_cells = table_scale.rows[r_idx].cells
        for c_idx, width in enumerate(scale_widths):
            row_cells[c_idx].width = width
            set_cell_margins(row_cells[c_idx], top=70, bottom=70, left=80, right=80)
            if r_idx % 2 == 1:
                set_cell_background(row_cells[c_idx], "F7FAFC")
        
        for idx, text in enumerate([r, s, vi, db]):
            p = row_cells[idx].paragraphs[0]
            p.alignment = WD_ALIGN_PARAGRAPH.CENTER if idx < 3 else WD_ALIGN_PARAGRAPH.LEFT
            run = p.add_run(text)
            run.font.size = Pt(8.5)
            if idx == 0:
                run.font.bold = True

    # Categories Data
    categories = [
        (
            "1. Functional Suitability",
            "Evaluates whether the system provides all essential functions required by faculty to draft, submit, manage, and evaluate student/academic activities.",
            [
                ("Proposal Lifecycle Management", "I can draft, edit, attach floor plans/posters, submit, and track the progress of my activity proposals."),
                ("Process Digitization", "The system effectively replaces traditional manual paper routing, physical sign-offs, and printed routing slips with an end-to-end digital approval workflow."),
                ("QR Code Generation & Routing", "The system automatically generates clear, scannable QR codes for approved activities that accurately open the participant evaluation form."),
                ("CSV Feedback Import & KPI Analytics", "I can upload participant evaluation CSV files, and the system accurately computes average ratings, satisfaction levels, and response breakdowns."),
                ("AI Proposal Guidance", "AI-assisted suggestions for activity titles, objectives, and descriptions provide contextually relevant, constructive, and helpful ideas during proposal drafting."),
                ("AI Feedback Analysis", "The AI accurately synthesizes and summarizes open-ended participant feedback into coherent, easy-to-read qualitative insights.")
            ]
        ),
        (
            "2. Compatibility & Interoperability",
            "Evaluates how seamlessly the system handles required file formats and workstation viewing.",
            [
                ("Workstation Display", "The system layout and form elements display clearly and fit comfortably on desktop and laptop computer screens."),
                ("Data Format Interoperability", "The system successfully exports and imports standard file types (PDF proposal summaries, CSV response files from Google Forms/Microsoft Forms, and image uploads).")
            ]
        ),
        (
            "3. Usability",
            "Evaluates how intuitive, learnable, and user-friendly the system interface is for faculty proponents.",
            [
                ("Appropriateness Recognizability", "The layout clearly identifies what each screen, button, and menu tab does without causing confusion."),
                ("Learnability", "A new faculty member can independently learn to draft and submit a proposal with minimal formal training or documentation."),
                ("Operability & Navigation", "Form fields, date pickers, dropdown selectors, and action buttons are easy to click, fill out, and navigate."),
                ("User Error Protection", "The system prevents accidental mistakes by validating required inputs and requesting confirmation before discarding drafts."),
                ("User Interface Aesthetics", "The interface is clean, uncluttered, visually engaging, and consistent with STI institutional branding."),
                ("Status Feedback", "The system provides clear status badges and notifications for proposal stages (Draft, Pending Review, Approved, or Returned for Revision).")
            ]
        ),
        (
            "4. Reliability",
            "Evaluates the stability, error handling, and operational dependability of the system during faculty workflows.",
            [
                ("Operational Faultlessness", "The application performs routine tasks reliably without unhandled errors, broken pages, or unexpected crashes."),
                ("Availability", "The system remains accessible and operational whenever faculty need to create proposals or check evaluation records."),
                ("Fault Tolerance", "If an invalid CSV format or unsupported file is uploaded, the system displays a clear warning rather than freezing or crashing.")
            ]
        ),
        (
            "5. Security & Access Control",
            "Evaluates how effectively the system protects faculty proposal data and account integrity.",
            [
                ("Confidentiality & Access Control", "Faculty can only view and edit their own activity proposals and drafts, keeping them private from unauthorized users."),
                ("Data Integrity", "Once a proposal is submitted or approved, it is locked against direct edits unless officially returned for revision."),
                ("Account Authenticity & Session Control", "User sessions are handled securely, requiring valid credentials and enforcing safe logout upon session expiration.")
            ]
        ),
        (
            "6. User Guidance & Clarity",
            "Evaluates clarity of error alerts and on-screen guidance.",
            [
                ("Error Message Clarity", "Error alerts provide understandable, actionable guidance on how to fix input issues rather than technical jargon."),
                ("On-Screen Guidance", "Tooltips, placeholder text, and form descriptions make it easy to understand requirements without external IT assistance.")
            ]
        ),
        (
            "7. Accessibility",
            "Evaluates the ease of accessing the system via standard web browsers.",
            [
                ("Zero-Client Installation", "The application is accessible instantly through standard web browsers without needing to install dedicated desktop software or plugins.")
            ]
        ),
        (
            "8. Overall Acceptability",
            "Evaluates the faculty proponent's holistic satisfaction, willingness to adopt, and endorsement of the system.",
            [
                ("Operational Value", "The automated system provides clear, measurable value and efficiency over the traditional manual paper proposal method."),
                ("Workflow Efficiency", "Using the system significantly reduces the time and administrative burden of submitting, routing, and evaluating activities."),
                ("Institutional Readiness", "The system is mature, reliable, and well-suited for full-scale institutional rollout across all departments of STI College Marikina."),
                ("Personal Endorsement", "I strongly endorse and prefer using this system for all my future activity proposals and post-event evaluation reports.")
            ]
        )
    ]

    # Render each Category
    cat_col_widths = [Inches(5.0), Inches(0.5), Inches(0.5), Inches(0.5), Inches(0.5)]

    for cat_title, cat_desc, questions in categories:
        # Category header
        p_c = doc.add_paragraph()
        p_c.paragraph_format.space_before = Pt(10)
        p_c.paragraph_format.space_after = Pt(2)
        p_c.paragraph_format.keep_with_next = True
        
        r_c = p_c.add_run(cat_title)
        r_c.font.bold = True
        r_c.font.size = Pt(10.5)
        r_c.font.color.rgb = sti_blue

        p_cd = doc.add_paragraph()
        p_cd.paragraph_format.space_before = Pt(0)
        p_cd.paragraph_format.space_after = Pt(4)
        p_cd.paragraph_format.keep_with_next = True
        r_cd = p_cd.add_run(cat_desc)
        r_cd.font.italic = True
        r_cd.font.size = Pt(8.5)
        r_cd.font.color.rgb = muted_gray

        table = doc.add_table(rows=len(questions) + 1, cols=5)
        table.alignment = WD_TABLE_ALIGNMENT.CENTER
        set_table_borders(table, color="D3D3D3", sz="4")

        # Header Row
        h_row = table.rows[0].cells
        for idx, w in enumerate(cat_col_widths):
            h_row[idx].width = w
            set_cell_background(h_row[idx], "003876")
            set_cell_margins(h_row[idx], top=80, bottom=80, left=80, right=80)
            p = h_row[idx].paragraphs[0]
            p.alignment = WD_ALIGN_PARAGRAPH.CENTER if idx > 0 else WD_ALIGN_PARAGRAPH.LEFT
            text = "Criterion / Statement" if idx == 0 else ["4", "3", "2", "1"][idx - 1]
            r = p.add_run(text)
            r.font.bold = True
            r.font.size = Pt(9)
            r.font.color.rgb = RGBColor(255, 255, 255)

        # Questions
        for q_idx, (q_title, q_body) in enumerate(questions, start=1):
            row = table.rows[q_idx].cells
            for c_idx, w in enumerate(cat_col_widths):
                row[c_idx].width = w
                set_cell_margins(row[c_idx], top=70, bottom=70, left=80, right=80)
                if q_idx % 2 == 1:
                    set_cell_background(row[c_idx], "FAFAFA")

            # Column 0: Question text
            p_q = row[0].paragraphs[0]
            p_q.alignment = WD_ALIGN_PARAGRAPH.LEFT
            r_title = p_q.add_run(f"{q_title}: ")
            r_title.font.bold = True
            r_title.font.size = Pt(8.5)
            r_body = p_q.add_run(q_body)
            r_body.font.size = Pt(8.5)

            # Columns 1-4: Checkboxes
            for c_idx in range(1, 5):
                p_box = row[c_idx].paragraphs[0]
                p_box.alignment = WD_ALIGN_PARAGRAPH.CENTER
                r_box = p_box.add_run("☐")
                r_box.font.size = Pt(11)
                r_box.font.color.rgb = RGBColor(160, 160, 160)

    # Comments Section
    p_comm = doc.add_paragraph()
    p_comm.paragraph_format.space_before = Pt(14)
    p_comm.paragraph_format.space_after = Pt(4)
    p_comm.paragraph_format.keep_with_next = True
    r_comm_head = p_comm.add_run("Comments & Qualitative Feedback\n")
    r_comm_head.font.bold = True
    r_comm_head.font.size = Pt(10)
    r_comm_head.font.color.rgb = sti_blue

    r_comm_sub = p_comm.add_run("Please provide any additional comments, observed issues, or suggestions for improving the faculty activity workflow:")
    r_comm_sub.font.italic = True
    r_comm_sub.font.size = Pt(8.5)
    r_comm_sub.font.color.rgb = muted_gray

    p_lines = doc.add_paragraph()
    p_lines.paragraph_format.space_before = Pt(4)
    for _ in range(4):
        p_lines.add_run("_________________________________________________________________________________\n")

    # Save
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    doc.save(output_path)
    print(f"Successfully generated Word document at: {output_path}")

if __name__ == "__main__":
    out_file = r"c:\xampp\htdocs\sti-activity-system\docs\FACULTY_UAT_QUESTIONNAIRE.docx"
    build_docx(out_file)
