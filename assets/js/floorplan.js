    // ════════════════════════════════════════════════════════
    //  FLOOR PLAN DRAWING ENGINE (Max 3 Floors: Ground, 2nd, 3rd)
    // ════════════════════════════════════════════════════════
    (function() {
      const CANVAS_W = 720;
      const CANVAS_H = 480;
      const GRID = 20;

      // Strict 3-floor definition: Ground Floor, 2nd Floor, 3rd Floor
      const ALLOWED_FLOORS = [
        { key: 'ground', label: 'Ground Floor', icon: '🏢' },
        { key: 'second', label: '2nd Floor', icon: '🏢' },
        { key: 'third', label: '3rd Floor', icon: '🏢' }
      ];
      const MAX_FLOORS = 3;

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

      // Multi-floor state container
      const floorData = {
        ground: {
          name: 'Ground Floor',
          shapes: [],
          history: [],
          redoStack: [],
          image: ''
        }
      };
      let activeFloor = 'ground';

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
      const gCtx = gridCanvas ? gridCanvas.getContext('2d') : null;

      function drawGrid() {
        if (!gridCanvas || !gCtx) return;
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
      if (stageDiv) {
        stageDiv.style.width = CANVAS_W + 'px';
        stageDiv.style.height = CANVAS_H + 'px';
      }
      const stageContainer = document.getElementById('fpStageContainer');
      if (stageContainer) {
        stageContainer.style.height = CANVAS_H + 'px';
      }
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
          const customColorEl = document.getElementById('fpCustomColor');
          if (customColorEl) customColorEl.value = fillColor;
          applyToSelected();
        });
      });

      const customColorEl = document.getElementById('fpCustomColor');
      if (customColorEl) {
        customColorEl.addEventListener('input', e => {
          fillColor = e.target.value;
          document.querySelectorAll('.fp-color-btn').forEach(b => b.classList.remove('selected'));
          applyToSelected();
        });
      }

      const strokeWidthEl = document.getElementById('fpStrokeWidth');
      if (strokeWidthEl) {
        strokeWidthEl.addEventListener('change', e => {
          strokeWidth = parseInt(e.target.value);
        });
      }

      function applyToSelected() {
        if (!selectedNode) return;
        if (typeof selectedNode.fill === 'function') selectedNode.fill(fillColor);
        mainLayer.batchDraw();
        saveHistory();
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
            fontFamily: "'Plus Jakarta Sans', sans-serif",
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
          fontFamily: "'Plus Jakarta Sans', sans-serif",
          fill: txtFill,
          listening: false
        });

        group.add(body);
        group.add(lbl);
        addShape(group);
        setStatus(`${p.label} placed on ${floorData[activeFloor]?.name || 'active floor'}. Drag to position it.`);
      }

      // ── Add shape & wire events ───────────────────────────
      function addShape(node) {
        mainLayer.add(node);
        mainLayer.batchDraw();
        wireShape(node);
        saveHistory();
        saveToHidden();
        renderFloorTabs();
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
              saveHistory();
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
        setStatus(`Shape selected on ${floorData[activeFloor]?.name}. Drag to move, use handles to resize/rotate.`);
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
        renderFloorTabs();
        setStatus(`Deleted shape from ${floorData[activeFloor]?.name}.`);
      }

      // ── Props panel ───────────────────────────────────────
      function updateProps(node) {
        const cls = node.getClassName();
        const rotEl = document.getElementById('propRot');
        const opacEl = document.getElementById('propOpac');
        const wEl = document.getElementById('propW');
        const hEl = document.getElementById('propH');
        const labelEl = document.getElementById('propLabel');

        if (rotEl) rotEl.value = Math.round(node.rotation() || 0);
        if (opacEl) opacEl.value = node.opacity() || 1;

        if (cls === 'Rect' || cls === 'Group') {
          if (wEl) wEl.value = Math.round(typeof node.width === 'function' ? node.width() : 0);
          if (hEl) hEl.value = Math.round(typeof node.height === 'function' ? node.height() : 0);
        } else if (cls === 'Ellipse') {
          if (wEl) wEl.value = Math.round((node.radiusX ? node.radiusX() : 0) * 2);
          if (hEl) hEl.value = Math.round((node.radiusY ? node.radiusY() : 0) * 2);
        }
        if (cls === 'Text' && labelEl) labelEl.value = node.text();
      }

      function clearProps() {
        ['propLabel', 'propW', 'propH', 'propRot', 'propOpac'].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.value = '';
        });
      }

      const pLabel = document.getElementById('propLabel');
      if (pLabel) {
        pLabel.addEventListener('change', e => {
          if (selectedNode && selectedNode.getClassName() === 'Text') {
            selectedNode.text(e.target.value);
            mainLayer.batchDraw();
            saveHistory();
            saveToHidden();
          }
        });
      }

      ['propW', 'propH'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
          el.addEventListener('change', e => {
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
            saveHistory();
            saveToHidden();
          });
        }
      });

      const pRot = document.getElementById('propRot');
      if (pRot) {
        pRot.addEventListener('change', e => {
          if (selectedNode) {
            selectedNode.rotation(parseFloat(e.target.value));
            tr.forceUpdate();
            mainLayer.batchDraw();
            saveHistory();
            saveToHidden();
          }
        });
      }

      const pOpac = document.getElementById('propOpac');
      if (pOpac) {
        pOpac.addEventListener('change', e => {
          if (selectedNode) {
            selectedNode.opacity(parseFloat(e.target.value));
            mainLayer.batchDraw();
            saveHistory();
            saveToHidden();
          }
        });
      }

      // ── Toolbar buttons ───────────────────────────────────
      const btnClear = document.getElementById('fpClear');
      if (btnClear) {
        btnClear.addEventListener('click', () => {
          const curName = floorData[activeFloor]?.name || 'current floor';
          if (!confirm(`Clear the entire layout on ${curName}?`)) return;
          mainLayer.destroyChildren();
          mainLayer.batchDraw();
          deselect();
          redoStack = [];
          history = [];
          saveHistory();
          saveToHidden();
          renderFloorTabs();
          setStatus(`${curName} cleared. Start drawing on this floor.`);
        });
      }

      const btnUndo = document.getElementById('fpUndo');
      if (btnUndo) btnUndo.addEventListener('click', undo);

      const btnRedo = document.getElementById('fpRedo');
      if (btnRedo) btnRedo.addEventListener('click', redo);

      const btnGrid = document.getElementById('fpGrid');
      if (btnGrid) {
        btnGrid.addEventListener('click', function() {
          gridOn = !gridOn;
          this.textContent = gridOn ? '⊞ Grid: On' : '⊞ Grid: Off';
          drawGrid();
        });
      }

      const btnZoomIn = document.getElementById('fpZoomIn');
      if (btnZoomIn) {
        btnZoomIn.addEventListener('click', () => {
          const sc = Math.min(stage.scaleX() * 1.2, 3);
          stage.scale({ x: sc, y: sc });
          stage.batchDraw();
          drawGrid();
        });
      }

      const btnZoomOut = document.getElementById('fpZoomOut');
      if (btnZoomOut) {
        btnZoomOut.addEventListener('click', () => {
          const sc = Math.max(stage.scaleX() / 1.2, 0.3);
          stage.scale({ x: sc, y: sc });
          stage.batchDraw();
          drawGrid();
        });
      }

      const btnZoomReset = document.getElementById('fpZoomReset');
      if (btnZoomReset) {
        btnZoomReset.addEventListener('click', () => {
          stage.scale({ x: 1, y: 1 });
          stage.position({ x: 0, y: 0 });
          stage.batchDraw();
          drawGrid();
        });
      }

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

      // ── History (per active floor) ─────────────────────────
      function saveHistory() {
        history.push(mainLayer.toJSON());
        if (history.length > 50) history.shift();
        redoStack = [];
        if (floorData[activeFloor]) {
          floorData[activeFloor].history = [...history];
          floorData[activeFloor].redoStack = [];
        }
      }

      function undo() {
        if (history.length < 2) {
          setStatus('Nothing to undo on this floor.');
          return;
        }
        redoStack.push(history.pop());
        restoreLayer(history[history.length - 1]);
        if (floorData[activeFloor]) {
          floorData[activeFloor].history = [...history];
          floorData[activeFloor].redoStack = [...redoStack];
        }
        renderFloorTabs();
      }

      function redo() {
        if (!redoStack.length) {
          setStatus('Nothing to redo on this floor.');
          return;
        }
        const next = redoStack.pop();
        history.push(next);
        restoreLayer(next);
        if (floorData[activeFloor]) {
          floorData[activeFloor].history = [...history];
          floorData[activeFloor].redoStack = [...redoStack];
        }
        renderFloorTabs();
      }

      function restoreLayer(json) {
        mainLayer.destroyChildren();
        try {
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
        } catch(e) {}
        mainLayer.batchDraw();
        deselect();
        saveToHidden();
      }

      // ── Multi-Floor State Machine (1 to 3 Floors Max) ──────
      function serializeNode(n) {
        // Use native Konva JSON serialization to preserve all children of Groups (presets) & shapes
        try {
          return JSON.parse(n.toJSON());
        } catch(e) {
          return { type: n.getClassName(), attrs: n.getAttrs() };
        }
      }

      function captureCurrentFloor() {
        if (!floorData[activeFloor]) return;
        floorData[activeFloor].shapes = Array.from(mainLayer.getChildren()).map(serializeNode);
        floorData[activeFloor].history = [...history];
        floorData[activeFloor].redoStack = [...redoStack];
        floorData[activeFloor].image = captureFloorImage(activeFloor);
      }

      function switchFloor(newFloorKey) {
        if (!newFloorKey) return;
        if (newFloorKey === activeFloor && floorData[newFloorKey]) return;

        // Auto-initialize floorData entry if it does not exist yet
        if (!floorData[newFloorKey]) {
          const def = ALLOWED_FLOORS.find(af => af.key === newFloorKey);
          floorData[newFloorKey] = {
            name: def ? def.label : newFloorKey,
            shapes: [],
            history: [],
            redoStack: [],
            image: ''
          };
        }

        // 1. Capture current floor state
        captureCurrentFloor();

        // 2. Switch active floor key
        activeFloor = newFloorKey;

        // 3. Clear stage selection and main layer
        deselect();
        mainLayer.destroyChildren();
        if (ghostLayer) ghostLayer.destroyChildren();

        // 4. Restore shapes for new active floor
        const target = floorData[activeFloor];
        const shapesToRestore = (target && target.shapes) ? Array.from(target.shapes) : [];
        shapesToRestore.forEach(shapeData => {
          try {
            const node = Konva.Node.create(shapeData);
            node.draggable(true);
            node.name('shape');
            mainLayer.add(node);
            wireShape(node);
          } catch(err) {
            console.warn('Error restoring shape on floor', activeFloor, err);
          }
        });
        mainLayer.batchDraw();
        trLayer.batchDraw();
        if (ghostLayer) ghostLayer.batchDraw();

        // 5. Restore history & redoStack
        history = (target && target.history && target.history.length > 0) ? [...target.history] : [mainLayer.toJSON()];
        redoStack = (target && target.redoStack) ? [...target.redoStack] : [];

        // 6. Update Floor Tabs UI
        renderFloorTabs();

        // 7. Update status & hidden inputs
        setStatus(`Switched to ${target.name}. All tools now operate on this floor.`);
        saveToHidden();
      }

      let lastAddFloorTime = 0;
      function addFloor(targetKey) {
        const now = Date.now();
        if (now - lastAddFloorTime < 300) return;
        lastAddFloorTime = now;

        let nextFloorDef = null;
        if (targetKey && typeof targetKey === 'string') {
          nextFloorDef = ALLOWED_FLOORS.find(af => af.key === targetKey);
        }
        if (!nextFloorDef) {
          nextFloorDef = ALLOWED_FLOORS.find(af => !floorData[af.key]);
        }
        if (!nextFloorDef) {
          setStatus('Maximum 3 floors reached (Ground Floor, 2nd Floor, 3rd Floor).');
          return;
        }

        // Capture current floor before switching
        captureCurrentFloor();

        // Initialize new floor if not already created
        if (!floorData[nextFloorDef.key]) {
          floorData[nextFloorDef.key] = {
            name: nextFloorDef.label,
            shapes: [],
            history: [],
            redoStack: [],
            image: ''
          };
        }

        // Switch to newly added floor
        switchFloor(nextFloorDef.key);
        setStatus(`Added ${nextFloorDef.label}. You can now draw its independent layout.`);
      }

      function removeFloor(floorKey) {
        if (floorKey === 'ground') {
          alert('Ground Floor is the primary floor and cannot be removed.');
          return;
        }
        if (!floorData[floorKey]) return;

        const floorObj = floorData[floorKey];
        const shapesCount = (floorKey === activeFloor)
          ? mainLayer.getChildren().length
          : (floorObj.shapes ? floorObj.shapes.length : 0);

        if (shapesCount > 0) {
          if (!confirm(`Are you sure you want to remove ${floorObj.name}? Any layout drawn on this floor will be deleted.`)) {
            return;
          }
        }

        // If the active floor is being removed, fallback to ground
        if (floorKey === activeFloor) {
          deselect();
          mainLayer.destroyChildren();
          activeFloor = 'ground';

          const groundFloor = floorData['ground'];
          const gShapes = (groundFloor && groundFloor.shapes) ? Array.from(groundFloor.shapes) : [];
          gShapes.forEach(shapeData => {
            try {
              const node = Konva.Node.create(shapeData);
              node.draggable(true);
              node.name('shape');
              mainLayer.add(node);
              wireShape(node);
            } catch(err) {}
          });
          mainLayer.batchDraw();
          trLayer.batchDraw();

          history = (groundFloor.history && groundFloor.history.length > 0) ? [...groundFloor.history] : [mainLayer.toJSON()];
          redoStack = groundFloor.redoStack ? [...groundFloor.redoStack] : [];
        }

        delete floorData[floorKey];
        renderFloorTabs();
        setStatus(`Removed ${floorObj.name}. Switched to Ground Floor.`);
        saveToHidden();
      }

      let hasScannedInitialTabs = false;
      function syncPreExistingDomTabs(tabsContainer) {
        if (hasScannedInitialTabs || !tabsContainer) return;
        hasScannedInitialTabs = true;
        const preExistingTabs = tabsContainer.querySelectorAll('.fp-floor-tab');
        preExistingTabs.forEach(t => {
          const k = t.dataset.floor;
          if (k && !floorData[k]) {
            const def = ALLOWED_FLOORS.find(af => af.key === k);
            floorData[k] = {
              name: def ? def.label : t.innerText.trim(),
              shapes: [],
              history: [],
              redoStack: [],
              image: ''
            };
          }
        });
      }

      function renderFloorTabs() {
        const tabsContainer = document.getElementById('fpFloorTabs');
        const addBtn = document.getElementById('fpAddFloorBtn');
        const maxBadge = document.getElementById('fpFloorMaxBadge');
        if (!tabsContainer) return;

        // Sync any initial static DOM tabs into floorData only once on initial boot
        syncPreExistingDomTabs(tabsContainer);

        // Set up event delegation once on container
        if (!tabsContainer._hasFloorDelegation) {
          tabsContainer._hasFloorDelegation = true;
          tabsContainer.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('.fp-floor-remove, [data-remove]');
            if (removeBtn) {
              e.stopPropagation();
              const key = removeBtn.dataset.remove || removeBtn.closest('[data-floor]')?.dataset.floor;
              if (key) removeFloor(key);
              return;
            }
            const tabBtn = e.target.closest('.fp-floor-tab');
            if (tabBtn && tabBtn.dataset.floor) {
              switchFloor(tabBtn.dataset.floor);
            }
          });
        }

        tabsContainer.innerHTML = '';

        const activeKeys = ALLOWED_FLOORS.filter(af => !!floorData[af.key]);
        activeKeys.forEach(floorDef => {
          const key = floorDef.key;
          const isCur = (key === activeFloor);
          const count = (key === activeFloor)
            ? mainLayer.getChildren().length
            : (floorData[key].shapes ? floorData[key].shapes.length : 0);

          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = `fp-floor-tab ${isCur ? 'active' : ''}`;
          btn.dataset.floor = key;
          btn.title = `Switch to ${floorDef.label}`;

          let html = `<span class="fp-floor-icon">${floorDef.icon || '🏢'}</span> <span class="fp-floor-label">${floorDef.label}</span>`;
          if (count > 0) {
            html += ` <span class="fp-floor-count" title="${count} items on this floor">${count}</span>`;
          }
          if (key !== 'ground') {
            html += ` <span class="fp-floor-remove" title="Remove ${floorDef.label}" data-remove="${key}">&times;</span>`;
          }
          btn.innerHTML = html;

          tabsContainer.appendChild(btn);
        });

        // Ensure addBtn listener is wired
        if (addBtn && !addBtn._hasAddListener) {
          addBtn._hasAddListener = true;
          addBtn.addEventListener('click', () => addFloor());
        }

        // Strict 3-floor ceiling:
        // Hide / disable "+ Add Floor" once 3 floors exist
        const countFloors = activeKeys.length;
        if (countFloors >= MAX_FLOORS) {
          if (addBtn) {
            addBtn.style.display = 'none';
            addBtn.disabled = true;
          }
          if (maxBadge) {
            maxBadge.textContent = 'Max 3 Floors';
            maxBadge.style.display = 'flex';
          }
        } else {
          if (addBtn) {
            addBtn.style.display = 'flex';
            addBtn.disabled = false;
            const nextDef = ALLOWED_FLOORS.find(af => !floorData[af.key]);
            addBtn.innerHTML = `＋ Add ${nextDef ? nextDef.label : 'Floor'}`;
          }
          if (maxBadge) {
            maxBadge.style.display = 'none';
          }
        }
      }

      // ── Floor Snapshot & Rendering Utilities ──────────────
      function captureFloorImage(floorKey) {
        if (floorKey === activeFloor) {
          const prevNodes = tr.nodes();
          tr.nodes([]);
          trLayer.batchDraw();
          let dataUrl = '';
          try {
            dataUrl = stage.toDataURL({ pixelRatio: 1 });
          } catch(e) {}
          if (prevNodes.length) {
            tr.nodes(prevNodes);
            trLayer.batchDraw();
          }
          return dataUrl;
        }
        if (floorData[floorKey]?.image) {
          return floorData[floorKey].image;
        }
        return renderShapesToDataUrl(floorData[floorKey]?.shapes || []);
      }

      function renderShapesToDataUrl(shapes) {
        if (!shapes || shapes.length === 0) {
          const blank = document.createElement('canvas');
          blank.width = CANVAS_W;
          blank.height = CANVAS_H;
          const bCtx = blank.getContext('2d');
          bCtx.fillStyle = '#ffffff';
          bCtx.fillRect(0, 0, CANVAS_W, CANVAS_H);
          return blank.toDataURL('image/png');
        }
        const offContainer = document.createElement('div');
        const offStage = new Konva.Stage({
          container: offContainer,
          width: CANVAS_W,
          height: CANVAS_H
        });
        const bgLayer = new Konva.Layer();
        bgLayer.add(new Konva.Rect({ x: 0, y: 0, width: CANVAS_W, height: CANVAS_H, fill: '#ffffff' }));
        offStage.add(bgLayer);
        const sLayer = new Konva.Layer();
        offStage.add(sLayer);
        shapes.forEach(s => {
          try {
            sLayer.add(Konva.Node.create(s));
          } catch(e) {}
        });
        offStage.draw();
        let url = '';
        try {
          url = offStage.toDataURL({ pixelRatio: 1 });
        } catch(e) {}
        offStage.destroy();
        return url;
      }

      function getFloorCanvasSync(floorKey) {
        if (floorKey === activeFloor) {
          const prevNodes = tr.nodes();
          tr.nodes([]);
          trLayer.batchDraw();
          let c = null;
          try {
            c = stage.toCanvas({ pixelRatio: 1 });
          } catch(e) {}
          if (prevNodes.length) {
            tr.nodes(prevNodes);
            trLayer.batchDraw();
          }
          return c;
        }

        const offContainer = document.createElement('div');
        const offStage = new Konva.Stage({
          container: offContainer,
          width: CANVAS_W,
          height: CANVAS_H
        });
        const bgLayer = new Konva.Layer();
        bgLayer.add(new Konva.Rect({ x: 0, y: 0, width: CANVAS_W, height: CANVAS_H, fill: '#ffffff' }));
        offStage.add(bgLayer);
        const sLayer = new Konva.Layer();
        offStage.add(sLayer);
        const shapes = floorData[floorKey]?.shapes || [];
        shapes.forEach(s => {
          try {
            sLayer.add(Konva.Node.create(s));
          } catch(e) {}
        });
        offStage.draw();
        let c = null;
        try {
          c = offStage.toCanvas({ pixelRatio: 1 });
        } catch(e) {}
        offStage.destroy();
        return c;
      }

      // ── Serialize to hidden inputs ────────────────────────
      function saveToHidden() {
        // Capture active floor latest state
        if (floorData[activeFloor]) {
          floorData[activeFloor].shapes = mainLayer.getChildren().map(serializeNode);
          floorData[activeFloor].image = captureFloorImage(activeFloor);
        }

        // Build clean floors structure
        const floorsPayload = {};
        let totalShapesAcrossFloors = 0;
        const allShapes = [];

        Object.keys(floorData).forEach(key => {
          const f = floorData[key];
          const shapes = f.shapes || [];
          floorsPayload[key] = {
            name: f.name,
            shapes: shapes,
            image: f.image || ''
          };
          if (shapes.length > 0) {
            totalShapesAcrossFloors += shapes.length;
            allShapes.push(...shapes);
          }
        });

        const activeShapes = floorData[activeFloor]?.shapes || [];
        const payload = {
          version: 2,
          activeFloor: activeFloor,
          floors: floorsPayload,
          shapes: activeShapes.length > 0 ? activeShapes : allShapes // Backwards compatibility for legacy readers
        };

        const dataInput = document.getElementById('fp-canvas-data');
        if (dataInput) {
          dataInput.value = JSON.stringify(payload);
        }

        // Remove invalid outline when user places a shape on any floor
        const invalidWrap = document.getElementById('fp-canvas-invalid-wrap');
        if (invalidWrap && totalShapesAcrossFloors > 0) {
          invalidWrap.classList.remove('fp-canvas-invalid');
        }

        // Update composite image for server storage & preview synchronously
        updateCompositeImage();
      }

      function updateCompositeImage() {
        const floorKeys = ALLOWED_FLOORS.map(af => af.key).filter(k => !!floorData[k]);
        const imgInput = document.getElementById('fp-canvas-image');
        if (!imgInput || floorKeys.length === 0) return;

        // If only 1 floor (1-3 floors mode), preserve single floor image behavior directly
        if (floorKeys.length === 1) {
          const singleDataUrl = captureFloorImage(floorKeys[0]);
          if (singleDataUrl) {
            imgInput.value = singleDataUrl;
            if (floorData[floorKeys[0]]) {
              floorData[floorKeys[0]].image = singleDataUrl;
            }
          }
          return;
        }

        // Multi-floor composite canvas: renders each floor (2 or 3) with header banner synchronously
        const bannerH = 40;
        const floorH = CANVAS_H;
        const totalW = CANVAS_W;
        const totalH = floorKeys.length * (bannerH + floorH);

        const offscreen = document.createElement('canvas');
        offscreen.width = totalW;
        offscreen.height = totalH;
        const ctx = offscreen.getContext('2d');

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, totalW, totalH);

        floorKeys.forEach((key, idx) => {
          const f = floorData[key];
          const yOffset = idx * (bannerH + floorH);

          // Draw floor header banner
          ctx.fillStyle = '#1e2a3a';
          ctx.fillRect(0, yOffset, totalW, bannerH);
          ctx.fillStyle = '#ffffff';
          ctx.font = 'bold 15px "Plus Jakarta Sans", sans-serif';
          ctx.textBaseline = 'middle';
          ctx.fillText(`🏢 ${f.name.toUpperCase()} LAYOUT`, 16, yOffset + bannerH / 2);

          // Render floor canvas synchronously
          const floorCanvas = getFloorCanvasSync(key);
          if (floorCanvas) {
            ctx.drawImage(floorCanvas, 0, yOffset + bannerH, totalW, floorH);
            ctx.strokeStyle = '#cbd5e1';
            ctx.lineWidth = 1;
            ctx.strokeRect(0, yOffset + bannerH, totalW, floorH);
          }
        });

        try {
          const compositeDataUrl = offscreen.toDataURL('image/png');
          imgInput.value = compositeDataUrl;
        } catch(e) {
          console.error('Failed to generate composite floor plan image', e);
        }
      }

      // ── Status ────────────────────────────────────────────
      function setStatus(msg) {
        const el = document.getElementById('fpStatus');
        if (el) el.textContent = msg;
      }

      // ── Bootloader ─────────────────────────────────────────
      if (window.FP_INITIAL_JSON) {
        try {
          let parsed = window.FP_INITIAL_JSON;
          if (typeof parsed === 'string') {
            try { parsed = JSON.parse(parsed); } catch(e) {}
          }
          if (typeof parsed === 'string') {
            try { parsed = JSON.parse(parsed); } catch(e) {}
          }

          if (parsed && typeof parsed === 'object') {
            if (parsed.floors && typeof parsed.floors === 'object') {
              // Version 2 Multi-floor format (1 to 3 floors)
              Object.keys(parsed.floors).forEach(k => {
                const def = ALLOWED_FLOORS.find(af => af.key === k);
                if (def) {
                  const f = parsed.floors[k];
                  floorData[k] = {
                    name: f.name || def.label,
                    shapes: f.shapes || [],
                    history: [],
                    redoStack: [],
                    image: f.image || ''
                  };
                }
              });
              if (parsed.activeFloor && floorData[parsed.activeFloor]) {
                activeFloor = parsed.activeFloor;
              } else {
                activeFloor = Object.keys(floorData)[0] || 'ground';
              }
            } else if (parsed.shapes && Array.isArray(parsed.shapes)) {
              // Legacy format v1 (single floor)
              floorData['ground'] = {
                name: 'Ground Floor',
                shapes: parsed.shapes,
                history: [],
                redoStack: [],
                image: ''
              };
              activeFloor = 'ground';
            } else if (Array.isArray(parsed)) {
              // Legacy raw array of shapes
              floorData['ground'] = {
                name: 'Ground Floor',
                shapes: parsed,
                history: [],
                redoStack: [],
                image: ''
              };
              activeFloor = 'ground';
            }
          }
        } catch(e) {
          console.error("Failed to load initial floor plan JSON", e);
        }
      }

      // Ensure ground floor is always initialized
      if (!floorData['ground']) {
        floorData['ground'] = {
          name: 'Ground Floor',
          shapes: [],
          history: [],
          redoStack: [],
          image: ''
        };
      }
      if (!floorData[activeFloor]) {
        activeFloor = 'ground';
      }

      // Render initial active floor shapes
      const initialFloor = floorData[activeFloor];
      (initialFloor.shapes || []).forEach(shapeData => {
        try {
          const node = Konva.Node.create(shapeData);
          node.draggable(true);
          node.name('shape');
          mainLayer.add(node);
          wireShape(node);
        } catch(e) {}
      });
      mainLayer.batchDraw();

      history = [mainLayer.toJSON()];
      initialFloor.history = [...history];

      // Initial render & sync
      renderFloorTabs();
      saveToHidden();
      setStatus(`Editing ${floorData[activeFloor]?.name || 'Ground Floor'}. Select a tool to start drawing.`);

      // Global hooks for external callers, form submission & step advance
      window.fpSaveToHidden = saveToHidden;
      window.fpAddFloor = addFloor;
      window.fpSwitchFloor = switchFloor;
      window.fpFloorData = floorData;
      window.fpGetActiveFloor = () => activeFloor;

      // Resilient document-level delegation for #fpAddFloorBtn
      document.addEventListener('click', function(e) {
        const btn = e.target.closest('#fpAddFloorBtn');
        if (btn && !btn.disabled) {
          addFloor();
        }
      });

      // Auto-sync on parent proposal form submit
      const parentForm = document.getElementById('proposalForm');
      if (parentForm) {
        parentForm.addEventListener('submit', () => {
          saveToHidden();
        });
      }

    })();
