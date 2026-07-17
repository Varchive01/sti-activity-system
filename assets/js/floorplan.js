    // ════════════════════════════════════════════════════════
    //  FLOOR PLAN DRAWING ENGINE
    // ════════════════════════════════════════════════════════
    (function() {
      const CANVAS_W = 720;
      const CANVAS_H = 480;
      const GRID = 20;

      let activeTool = 'select';
      let fillColor = '#dbeafe';
      let strokeWidth = 2;
      let gridOn = true;
      let selectedNode = null;

      let history = [];
      let redoStack = [];
      let isDrawing = false;
      let drawStart = {
        x: 0,
        y: 0
      };
      let ghostShape = null;

      // ── Konva init ───────────────────────────────────────
      const stage = new Konva.Stage({
        container: 'fpStageDiv',
        width: CANVAS_W,
        height: CANVAS_H,
      });

      const mainLayer = new Konva.Layer();
      const ghostLayer = new Konva.Layer();
      const trLayer = new Konva.Layer();
      stage.add(mainLayer);
      stage.add(ghostLayer);
      stage.add(trLayer);

      const tr = new Konva.Transformer({
        rotateEnabled: true,
        enabledAnchors: ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'middle-right', 'middle-left'],
        boundBoxFunc: (old, nb) => (nb.width < 10 || nb.height < 10) ? old : nb,
      });
      trLayer.add(tr);

      // ── Grid overlay ─────────────────────────────────────
      const gridCanvas = document.getElementById('fpGridCanvas');
      const gCtx = gridCanvas.getContext('2d');

      function drawGrid() {
        const sc = stage.scaleX();
        const W = CANVAS_W * sc,
          H = CANVAS_H * sc;
        gridCanvas.width = W;
        gridCanvas.height = H;
        gCtx.clearRect(0, 0, W, H);
        if (!gridOn) return;
        gCtx.strokeStyle = '#e5e7eb';
        gCtx.lineWidth = 0.5;
        const step = GRID * sc;
        for (let x = 0; x <= W; x += step) {
          gCtx.beginPath();
          gCtx.moveTo(x, 0);
          gCtx.lineTo(x, H);
          gCtx.stroke();
        }
        for (let y = 0; y <= H; y += step) {
          gCtx.beginPath();
          gCtx.moveTo(0, y);
          gCtx.lineTo(W, y);
          gCtx.stroke();
        }
      }

      // Set container dimensions
      const stageDiv = document.getElementById('fpStageDiv');
      stageDiv.style.width = CANVAS_W + 'px';
      stageDiv.style.height = CANVAS_H + 'px';
      document.getElementById('fpStageContainer').style.height = CANVAS_H + 'px';
      drawGrid();

      // ── Snap ─────────────────────────────────────────────
      function snap(v) {
        return Math.round(v / GRID) * GRID;
      }

      function snapPt(x, y) {
        return gridOn ? {
          x: snap(x),
          y: snap(y)
        } : {
          x,
          y
        };
      }

      // ── Tool palette ─────────────────────────────────────
      document.querySelectorAll('.fp-tool-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const tool = btn.dataset.tool;

          if (tool.startsWith('preset-')) {
            deselect();
            dropPreset(tool.replace('preset-', ''));
            return;
          }
          if (tool === 'eraser') {
            deleteSelected();
            return;
          }

          deselect();
          activeTool = tool;
          document.querySelectorAll('.fp-tool-btn').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          stage.container().style.cursor = tool === 'select' ? 'default' : 'crosshair';
          setStatus(toolHint(tool));
        });
      });

      function toolHint(t) {
        return {
          select: 'Click a shape to select it. Drag to move. Use handles to resize.',
          rect: 'Click and drag to draw a room or area rectangle.',
          line: 'Click and drag to draw a wall or boundary.',
          arrow: 'Click and drag to draw a directional arrow.',
          ellipse: 'Click and drag to draw a circular table or feature.',
          text: 'Click anywhere to place a text label.',
        } [t] || '';
      }

      // ── Color / stroke toolbar ────────────────────────────
      document.querySelectorAll('.fp-color-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          fillColor = btn.dataset.color;
          document.querySelectorAll('.fp-color-btn').forEach(b => b.classList.remove('selected'));
          btn.classList.add('selected');
          document.getElementById('fpCustomColor').value = fillColor;
          applyToSelected();
        });
      });
      document.getElementById('fpCustomColor').addEventListener('input', e => {
        fillColor = e.target.value;
        document.querySelectorAll('.fp-color-btn').forEach(b => b.classList.remove('selected'));
        applyToSelected();
      });
      document.getElementById('fpStrokeWidth').addEventListener('change', e => {
        strokeWidth = parseInt(e.target.value);
      });

      function applyToSelected() {
        if (!selectedNode) return;
        if (typeof selectedNode.fill === 'function') selectedNode.fill(fillColor);
        mainLayer.batchDraw();
        saveToHidden();
      }

      // ── Drawing interactions ──────────────────────────────
      stage.on('mousedown touchstart', e => {
        if (activeTool === 'select') return;
        if (e.target !== stage && e.target.getLayer() !== ghostLayer) return;

        const pos = stage.getPointerPosition();
        if (!pos) return;
        const pt = snapPt(pos.x, pos.y);
        drawStart = pt;
        isDrawing = true;

        if (activeTool === 'text') {
          const label = prompt('Enter label text:', 'Label');
          if (!label) {
            isDrawing = false;
            return;
          }
          const txt = new Konva.Text({
            x: pt.x,
            y: pt.y,
            text: label,
            fontSize: 13,
            fontFamily: 'Inter, sans-serif',
            fill: '#1e2a3a',
            draggable: true,
            name: 'shape'
          });
          addShape(txt);
          isDrawing = false;
          return;
        }

        ghostShape = buildGhost(activeTool, pt);
        if (ghostShape) ghostLayer.add(ghostShape);
        ghostLayer.batchDraw();
      });

      stage.on('mousemove touchmove', () => {
        if (!isDrawing || !ghostShape) return;
        const pos = stage.getPointerPosition();
        if (!pos) return;
        const pt = snapPt(pos.x, pos.y);
        const w = pt.x - drawStart.x,
          h = pt.y - drawStart.y;

        if (activeTool === 'rect') {
          ghostShape.x(Math.min(drawStart.x, pt.x));
          ghostShape.y(Math.min(drawStart.y, pt.y));
          ghostShape.width(Math.abs(w));
          ghostShape.height(Math.abs(h));
        } else if (activeTool === 'ellipse') {
          ghostShape.x(drawStart.x + w / 2);
          ghostShape.y(drawStart.y + h / 2);
          ghostShape.radiusX(Math.abs(w / 2));
          ghostShape.radiusY(Math.abs(h / 2));
        } else if (activeTool === 'line' || activeTool === 'arrow') {
          ghostShape.points([drawStart.x, drawStart.y, pt.x, pt.y]);
        }
        ghostLayer.batchDraw();
      });

      stage.on('mouseup touchend', () => {
        if (!isDrawing) return;
        isDrawing = false;
        if (ghostShape) {
          ghostShape.destroy();
          ghostLayer.batchDraw();
        }

        const pos = stage.getPointerPosition();
        if (!pos) {
          ghostShape = null;
          return;
        }
        const pt = snapPt(pos.x, pos.y);
        const w = Math.abs(pt.x - drawStart.x);
        const h = Math.abs(pt.y - drawStart.y);

        if (w < 5 && h < 5) {
          ghostShape = null;
          return;
        }

        let shape = null;
        if (activeTool === 'rect') {
          shape = new Konva.Rect({
            x: Math.min(drawStart.x, pt.x),
            y: Math.min(drawStart.y, pt.y),
            width: w,
            height: h,
            fill: fillColor,
            stroke: '#1e2a3a',
            strokeWidth,
            cornerRadius: 3,
            draggable: true,
            name: 'shape'
          });
        } else if (activeTool === 'ellipse') {
          shape = new Konva.Ellipse({
            x: drawStart.x + (pt.x - drawStart.x) / 2,
            y: drawStart.y + (pt.y - drawStart.y) / 2,
            radiusX: w / 2,
            radiusY: h / 2,
            fill: fillColor,
            stroke: '#1e2a3a',
            strokeWidth,
            draggable: true,
            name: 'shape'
          });
        } else if (activeTool === 'line') {
          shape = new Konva.Line({
            points: [drawStart.x, drawStart.y, pt.x, pt.y],
            stroke: '#1e2a3a',
            strokeWidth,
            lineCap: 'round',
            draggable: true,
            name: 'shape'
          });
        } else if (activeTool === 'arrow') {
          shape = new Konva.Arrow({
            points: [drawStart.x, drawStart.y, pt.x, pt.y],
            stroke: '#1e2a3a',
            fill: '#1e2a3a',
            strokeWidth,
            pointerLength: 10,
            pointerWidth: 8,
            draggable: true,
            name: 'shape'
          });
        }

        if (shape) addShape(shape);
        ghostShape = null;
      });

      stage.on('click tap', e => {
        if (e.target === stage) deselect();
      });

      // ── Ghost preview factory ─────────────────────────────
      function buildGhost(tool, pt) {
        const cfg = {
          fill: 'rgba(59,130,246,0.12)',
          stroke: '#3b82f6',
          strokeWidth: 1,
          listening: false
        };
        if (tool === 'rect') return new Konva.Rect({
          ...cfg,
          x: pt.x,
          y: pt.y,
          width: 1,
          height: 1
        });
        if (tool === 'ellipse') return new Konva.Ellipse({
          ...cfg,
          x: pt.x,
          y: pt.y,
          radiusX: 1,
          radiusY: 1
        });
        if (tool === 'line') return new Konva.Line({
          ...cfg,
          fill: null,
          points: [pt.x, pt.y, pt.x, pt.y],
          lineCap: 'round'
        });
        if (tool === 'arrow') return new Konva.Arrow({
          ...cfg,
          fill: cfg.stroke,
          points: [pt.x, pt.y, pt.x, pt.y]
        });
        return null;
      }

      // ── Presets ───────────────────────────────────────────
      const PRESETS = {
        stage: {
          label: 'Stage',
          fill: '#1e2a3a',
          stroke: '#0f172a',
          w: 200,
          h: 60,
          shape: 'rect'
        },
        table: {
          label: 'Table',
          fill: '#dcfce7',
          stroke: '#16a34a',
          w: 40,
          h: 40,
          shape: 'ellipse'
        },
        booth: {
          label: 'Booth',
          fill: '#fef9c3',
          stroke: '#ca8a04',
          w: 80,
          h: 50,
          shape: 'rect'
        },
        exit: {
          label: 'Exit',
          fill: '#fce7f3',
          stroke: '#db2777',
          w: 40,
          h: 12,
          shape: 'rect'
        },
        restroom: {
          label: 'Restroom',
          fill: '#f3f4f6',
          stroke: '#4b5563',
          w: 50,
          h: 50,
          shape: 'rect'
        },
      };

      function dropPreset(key) {
        const p = PRESETS[key];
        if (!p) return;
        const cx = snap(CANVAS_W / 2 - p.w / 2);
        const cy = snap(CANVAS_H / 2 - p.h / 2);
        const txtFill = p.fill === '#1e2a3a' ? '#ffffff' : '#1e2a3a';

        const group = new Konva.Group({
          x: cx,
          y: cy,
          draggable: true,
          name: 'shape'
        });

        let body;
        if (p.shape === 'rect') {
          body = new Konva.Rect({
            width: p.w,
            height: p.h,
            fill: p.fill,
            stroke: p.stroke,
            strokeWidth: 2,
            cornerRadius: 3
          });
        } else {
          body = new Konva.Ellipse({
            x: p.w / 2,
            y: p.h / 2,
            radiusX: p.w / 2,
            radiusY: p.h / 2,
            fill: p.fill,
            stroke: p.stroke,
            strokeWidth: 2
          });
        }

        const lbl = new Konva.Text({
          x: 0,
          y: p.h / 2 - 7,
          width: p.w,
          align: 'center',
          text: p.label,
          fontSize: 11,
          fontFamily: 'Inter, sans-serif',
          fill: txtFill,
          listening: false
        });

        group.add(body);
        group.add(lbl);
        addShape(group);
        setStatus(`${p.label} placed at center — drag to position it.`);
      }

      // ── Add shape & wire events ───────────────────────────
      function addShape(node) {
        mainLayer.add(node);
        mainLayer.batchDraw();
        wireShape(node);
        saveHistory();
        saveToHidden();
        selectNode(node);
      }

      function wireShape(node) {
        node.on('click tap', e => {
          e.cancelBubble = true;
          selectNode(node);
        });
        node.on('dragend transformend', () => {
          saveHistory();
          saveToHidden();
        });
        node.on('dblclick dbltap', () => {
          if (node.getClassName() === 'Text') {
            const nv = prompt('Edit label:', node.text());
            if (nv !== null) {
              node.text(nv);
              mainLayer.batchDraw();
              saveToHidden();
            }
          }
        });
      }

      function selectNode(node) {
        selectedNode = node;
        tr.nodes([node]);
        trLayer.batchDraw();
        updateProps(node);
        setStatus('Shape selected. Drag to move, use handles to resize/rotate.');
      }

      function deselect() {
        selectedNode = null;
        tr.nodes([]);
        trLayer.batchDraw();
        clearProps();
      }

      function deleteSelected() {
        if (!selectedNode) {
          setStatus('Select a shape first, then click Delete.');
          return;
        }
        selectedNode.destroy();
        tr.nodes([]);
        trLayer.batchDraw();
        mainLayer.batchDraw();
        selectedNode = null;
        clearProps();
        saveHistory();
        saveToHidden();
      }

      // ── Props panel ───────────────────────────────────────
      function updateProps(node) {
        const cls = node.getClassName();
        document.getElementById('propRot').value = Math.round(node.rotation() || 0);
        document.getElementById('propOpac').value = node.opacity() || 1;
        if (cls === 'Rect' || cls === 'Group') {
          document.getElementById('propW').value = Math.round(typeof node.width === 'function' ? node.width() : 0);
          document.getElementById('propH').value = Math.round(typeof node.height === 'function' ? node.height() : 0);
        } else if (cls === 'Ellipse') {
          document.getElementById('propW').value = Math.round((node.radiusX ? node.radiusX() : 0) * 2);
          document.getElementById('propH').value = Math.round((node.radiusY ? node.radiusY() : 0) * 2);
        }
        if (cls === 'Text') document.getElementById('propLabel').value = node.text();
      }

      function clearProps() {
        ['propLabel', 'propW', 'propH', 'propRot', 'propOpac'].forEach(id => document.getElementById(id).value = '');
      }

      document.getElementById('propLabel').addEventListener('change', e => {
        if (selectedNode && selectedNode.getClassName() === 'Text') {
          selectedNode.text(e.target.value);
          mainLayer.batchDraw();
          saveToHidden();
        }
      });
      ['propW', 'propH'].forEach(id => {
        document.getElementById(id).addEventListener('change', e => {
          if (!selectedNode) return;
          const v = parseInt(e.target.value);
          if (id === 'propW') {
            if (typeof selectedNode.width === 'function') selectedNode.width(v);
            if (typeof selectedNode.radiusX === 'function') selectedNode.radiusX(v / 2);
          } else {
            if (typeof selectedNode.height === 'function') selectedNode.height(v);
            if (typeof selectedNode.radiusY === 'function') selectedNode.radiusY(v / 2);
          }
          tr.forceUpdate();
          mainLayer.batchDraw();
          saveToHidden();
        });
      });
      document.getElementById('propRot').addEventListener('change', e => {
        if (selectedNode) {
          selectedNode.rotation(parseFloat(e.target.value));
          tr.forceUpdate();
          mainLayer.batchDraw();
          saveToHidden();
        }
      });
      document.getElementById('propOpac').addEventListener('change', e => {
        if (selectedNode) {
          selectedNode.opacity(parseFloat(e.target.value));
          mainLayer.batchDraw();
          saveToHidden();
        }
      });

      // ── Toolbar buttons ───────────────────────────────────
      document.getElementById('fpClear').addEventListener('click', () => {
        if (!confirm('Clear the entire floor plan?')) return;
        mainLayer.destroyChildren();
        mainLayer.batchDraw();
        deselect();
        redoStack = [];
        history = [];
        saveToHidden();
        setStatus('Canvas cleared. Start drawing your floor plan.');
      });

      document.getElementById('fpUndo').addEventListener('click', undo);
      document.getElementById('fpRedo').addEventListener('click', redo);

      document.getElementById('fpGrid').addEventListener('click', function() {
        gridOn = !gridOn;
        this.textContent = gridOn ? '⊞ Grid: On' : '⊞ Grid: Off';
        drawGrid();
      });

      document.getElementById('fpZoomIn').addEventListener('click', () => {
        const sc = Math.min(stage.scaleX() * 1.2, 3);
        stage.scale({
          x: sc,
          y: sc
        });
        stage.batchDraw();
        drawGrid();
      });
      document.getElementById('fpZoomOut').addEventListener('click', () => {
        const sc = Math.max(stage.scaleX() / 1.2, 0.3);
        stage.scale({
          x: sc,
          y: sc
        });
        stage.batchDraw();
        drawGrid();
      });
      document.getElementById('fpZoomReset').addEventListener('click', () => {
        stage.scale({
          x: 1,
          y: 1
        });
        stage.position({
          x: 0,
          y: 0
        });
        stage.batchDraw();
        drawGrid();
      });

      // ── Keyboard shortcuts ───────────────────────────────
      document.addEventListener('keydown', e => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if ((e.key === 'Delete' || e.key === 'Backspace') && selectedNode) deleteSelected();
        if (e.ctrlKey && e.key === 'z') {
          e.preventDefault();
          undo();
        }
        if (e.ctrlKey && e.key === 'y') {
          e.preventDefault();
          redo();
        }
      });

      // ── History ───────────────────────────────────────────
      function saveHistory() {
        history.push(mainLayer.toJSON());
        if (history.length > 50) history.shift();
        redoStack = [];
      }

      function undo() {
        if (history.length < 2) {
          setStatus('Nothing to undo.');
          return;
        }
        redoStack.push(history.pop());
        restoreLayer(history[history.length - 1]);
      }

      function redo() {
        if (!redoStack.length) {
          setStatus('Nothing to redo.');
          return;
        }
        const next = redoStack.pop();
        history.push(next);
        restoreLayer(next);
      }

      function restoreLayer(json) {
        mainLayer.destroyChildren();
        const parsed = JSON.parse(json);
        (parsed.children || []).forEach(child => {
          try {
            const node = Konva.Node.create(child);
            node.draggable(true);
            node.name('shape');
            mainLayer.add(node);
            wireShape(node);
          } catch (e) {}
        });
        mainLayer.batchDraw();
        deselect();
        saveToHidden();
      }

      // ── Serialize to hidden inputs ────────────────────────
      function saveToHidden() {
        const shapes = mainLayer.getChildren().map(n => ({
          type: n.getClassName(),
          attrs: n.getAttrs()
        }));
        document.getElementById('fp-canvas-data').value = JSON.stringify({
          shapes
        });
        // Remove invalid outline when user places a shape
        if (shapes.length > 0) document.getElementById('fp-canvas-invalid-wrap').classList.remove('fp-canvas-invalid');
        try {
          document.getElementById('fp-canvas-image').value = stage.toDataURL({
            pixelRatio: 1
          });
        } catch (e) {}
      }

      // ── Status ────────────────────────────────────────────
      function setStatus(msg) {
        document.getElementById('fpStatus').textContent = msg;
      }

      // ── Boot ─────────────────────────────────────────────
      saveHistory();
      
      if (window.FP_INITIAL_JSON) {
        try {
          const parsed = JSON.parse(window.FP_INITIAL_JSON);
          if (parsed && parsed.shapes) {
            parsed.shapes.forEach(shapeData => {
              const node = Konva.Node.create(shapeData);
              node.draggable(true);
              node.name('shape');
              mainLayer.add(node);
              wireShape(node);
            });
            mainLayer.batchDraw();
            saveHistory();
            saveToHidden();
          }
        } catch(e) {
          console.error("Failed to load initial floor plan JSON", e);
        }
      }

      setStatus('Select a tool from the left panel to start drawing your floor plan.');

    })();
