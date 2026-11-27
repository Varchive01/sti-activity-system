import os
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml import parse_xml
from docx.oxml.ns import nsdecls

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

def build_program_head_docx(output_path):
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

    run_role = p_title.add_run("Evaluation Instrument for Program Heads (Academic Department Leads)")
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
    cells[0].paragraphs[0].add_run("Program Head Name:").font.bold = True
    cells[1].paragraphs[0].add_run("_________________________________________________________")

    cells = table_meta.rows[1].cells
    cells[0].paragraphs[0].add_run("Department / Program:").font.bold = True
    cells[1].paragraphs[0].add_run("[   ] Information Technology (IT) / Computer Science\n[   ] Tourism & Hospitality Management (THM)\n[   ] General Education (GE)")

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
        ("4", "Strongly Agree", "Highly Acceptable", "Feature performs flawlessly; fully meets and exceeds departmental expectations."),
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

    # Categories Data (Fully fixed for Program Head role)
    categories = [
        (
            "1. Functional Suitability",
            "Evaluates whether the system provides all essential functions required by Program Heads to monitor department activities, oversee faculty tasks, collaborate on proposals, and review post-event outcomes.",
            [
                ("Department Activity Overview", "I can easily view, filter, and track all activity proposals and ongoing events submitted by faculty members within my assigned academic department."),
                ("Collaborative Proposal Authoring", "I can create departmental activity proposals, assign faculty members as co-authors or contributors, and submit drafts for administrative review without technical errors."),
                ("Department Task Monitoring", "I can monitor faculty task assignments, check progress percentages, and identify delayed or approaching task deadlines across all department activities."),
                ("Faculty Contributor Oversight", "The Faculty Contributors directory accurately presents each department member's active roles, task assignments, and overall participation in campus events."),
                ("Cross-Department Visibility", "I can clearly track shared activities where faculty members from my department are assigned to assist in events hosted by other departments."),
                ("Post-Event & Department KPI Analytics", "I can review attendee satisfaction scores, evaluation feedback, and generate department activity accomplishment reports.")
            ]
        ),
        (
            "2. Compatibility & Interoperability",
            "Evaluates how seamlessly the system handles departmental data, file formats, and workstation displays.",
            [
                ("Workstation & Mobile Display", "Dashboard statistics, task monitoring tables, and calendar views fit comfortably on desktop, laptop, and tablet screens without visual clipping."),
                ("Document Interoperability", "Attached supporting documents (PDF guidelines, floor plans, and budget tables) open and display reliably without formatting or download errors.")
            ]
        ),
        (
            "3. Usability",
            "Evaluates the intuitiveness, efficiency, and clarity of the departmental navigation and monitoring interface.",
            [
                ("Appropriateness Recognizability", "Proposal statuses (Draft, Under Review, Endorsed, Approved, Returned for Revision) and task statuses (Not Started, In Progress, Completed, Delayed) are immediately recognizable."),
                ("Learnability", "A newly appointed Program Head can learn to navigate the department dashboard, monitor tasks, and review activity records with minimal training."),
                ("Navigation & Operability", "Switching between Department Activities, Task Monitoring, Faculty Contributors, and KPI Reports is smooth, responsive, and intuitive."),
                ("User Error Protection", "The system prompts for confirmation before submitting proposals or updating collaborator roles, preventing accidental data loss."),
                ("User Interface Aesthetics", "The departmental interface is clean, professional, well-organized, and visually consistent with STI brand colors and styling.")
            ]
        ),
        (
            "4. Reliability",
            "Evaluates the system's operational stability, dependable data saving, and uptime.",
            [
                ("Operational Faultlessness", "Department dashboard pages, task updates, and report generation execute smoothly without unhandled PHP errors, broken links, or system crashes."),
                ("Availability", "The system remains consistently accessible during critical departmental planning cycles, term ends, and semester evaluations."),
                ("Data Integrity", "Proposal revisions, task progress percentages, and collaborator assignments are saved accurately in the database without data loss.")
            ]
        ),
        (
            "5. Security & Access Control",
            "Evaluates data isolation, role-based boundaries, and confidentiality.",
            [
                ("Departmental Data Isolation", "I can only access proposals and sensitive faculty records strictly belonging to my assigned department (e.g., IT Head accesses IT only, THM accesses THM only) with zero unauthorized cross-department data exposure."),
                ("Role Separation & Non-Repudiation", "Departmental modifications and proposal submissions are strictly tied to my authenticated credentials and protected against unauthorized edits."),
                ("Account & Session Security", "Administrative sessions require secure authentication and safely time out after prolonged inactivity to protect sensitive departmental data.")
            ]
        ),
        (
            "6. User Guidance & Maintainability",
            "Evaluates administrative clarity, deadline alerts, and system feedback.",
            [
                ("Task Guidance & Status Indicators", "The interface clearly highlights overdue tasks and nearing deadlines with visual badges so that follow-ups can be made promptly."),
                ("Actionable Notifications", "The system provides clear notifications when departmental proposals are endorsed, returned for revisions with reviewer comments, or finally approved.")
            ]
        ),
        (
            "7. Portability & Accessibility",
            "Evaluates the ease of accessing departmental management tools across devices and campus networks.",
            [
                ("Zero-Client Installation", "Full departmental monitoring and proposal features are accessible instantly through standard modern web browsers without desktop client installation."),
                ("Network & Device Adaptability", "The system functions reliably whether accessed from the Program Head's office desktop, home Wi-Fi, or mobile connection.")
            ]
        ),
        (
            "8. Overall Acceptability",
            "Evaluates the Program Head's holistic satisfaction, administrative efficiency, and institutional endorsement.",
            [
                ("Administrative Value", "Centralizing departmental proposals, task monitoring, and post-event records in STI-ARMES significantly reduces administrative overhead compared to manual paper tracking."),
                ("Decision Support Quality", "The system provides superior visibility and real-time oversight over department faculty workload, activity execution, and student engagement."),
                ("Institutional Readiness", "The platform is mature, robust, and well-prepared for full-scale operational deployment across all academic programs at STI College Marikina."),
                ("Personal Endorsement", "I strongly endorse and prefer using STI-ARMES for all future departmental activity management and faculty task monitoring.")
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

    r_comm_sub = p_comm.add_run("Please provide any additional comments, observed issues, or suggestions for improving the departmental management and faculty activity workflow:")
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
    out_file = r"c:\xampp\htdocs\sti-activity-system\docs\PROGRAM_HEAD_UAT_QUESTIONNAIRE.docx"
    build_program_head_docx(out_file)
