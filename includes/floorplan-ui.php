              <!-- Hidden inputs carry canvas data to server -->
              <input type="hidden" name="floor_plan_json" id="fp-canvas-data">
              <input type="hidden" name="floor_plan_image" id="fp-canvas-image">

              <div id="fp-canvas-invalid-wrap">
                <!-- ── Drawing Tool ── -->
                <div class="fp-wrap" id="fpWrap">

                  <!-- Left tool palette -->
                  <div class="fp-palette">
                    <!-- Draw tools -->
                    <button type="button" class="fp-tool-btn active" data-tool="select" title="Select / Move">
                      ↖<span class="fp-label">Select</span>
                    </button>
                    <div class="fp-palette-sep"></div>
                    <button type="button" class="fp-tool-btn" data-tool="rect" title="Rectangle (walls, rooms)">
                      ▭<span class="fp-label">Room</span>
                    </button>
                    <button type="button" class="fp-tool-btn" data-tool="line" title="Draw a line / wall">
                      ╱<span class="fp-label">Wall</span>
                    </button>
                    <button type="button" class="fp-tool-btn" data-tool="arrow" title="Draw arrow / direction">
                      →<span class="fp-label">Arrow</span>
                    </button>
                    <button type="button" class="fp-tool-btn" data-tool="ellipse" title="Circle / oval">
                      ◯<span class="fp-label">Circle</span>
                    </button>
                    <div class="fp-palette-sep"></div>
                    <!-- Preset shapes -->
                    <button type="button" class="fp-tool-btn" data-tool="preset-stage" title="Add Stage">
                      🎤<span class="fp-label">Stage</span>
                    </button>
                    <button type="button" class="fp-tool-btn" data-tool="preset-table" title="Add Table (round)">
                      🪑<span class="fp-label">Table</span>
                    </button>
                    <button type="button" class="fp-tool-btn" data-tool="preset-booth" title="Add Booth">
                      🏪<span class="fp-label">Booth</span>
                    </button>
                    <button type="button" class="fp-tool-btn" data-tool="preset-exit" title="Add Exit / Door">
                      🚪<span class="fp-label">Exit</span>
                    </button>
                    <button type="button" class="fp-tool-btn" data-tool="preset-restroom" title="Add Restroom">
                      🚻<span class="fp-label">WC</span>
                    </button>
                    <div class="fp-palette-sep"></div>
                    <button type="button" class="fp-tool-btn" data-tool="text" title="Add text label">
                      T<span class="fp-label">Text</span>
                    </button>
                    <button type="button" class="fp-tool-btn" data-tool="eraser" title="Delete selected">
                      🗑<span class="fp-label">Delete</span>
                    </button>
                  </div>

                  <!-- Canvas + toolbar -->
                  <div class="fp-canvas-wrap">
                    <div class="fp-toolbar">
                      <label>Fill:</label>
                      <button type="button" class="fp-color-btn selected" data-color="#dbeafe" style="background:#dbeafe" title="Light Blue"></button>
                      <button type="button" class="fp-color-btn" data-color="#dcfce7" style="background:#dcfce7" title="Light Green"></button>
                      <button type="button" class="fp-color-btn" data-color="#fef9c3" style="background:#fef9c3" title="Light Yellow"></button>
                      <button type="button" class="fp-color-btn" data-color="#fce7f3" style="background:#fce7f3" title="Light Pink"></button>
                      <button type="button" class="fp-color-btn" data-color="#f3f4f6" style="background:#f3f4f6" title="Light Gray"></button>
                      <button type="button" class="fp-color-btn" data-color="#1e2a3a" style="background:#1e2a3a" title="Dark Navy"></button>
                      <input type="color" class="fp-color-input" id="fpCustomColor" value="#dbeafe" title="Custom color">

                      <div class="fp-toolbar-sep"></div>
                      <label>Stroke:</label>
                      <select class="fp-stroke-select" id="fpStrokeWidth">
                        <option value="1">Thin</option>
                        <option value="2" selected>Normal</option>
                        <option value="4">Thick</option>
                        <option value="6">Heavy</option>
                      </select>

                      <div class="fp-toolbar-sep"></div>
                      <button type="button" class="fp-action-btn" id="fpUndo">↩ Undo</button>
                      <button type="button" class="fp-action-btn" id="fpRedo">↪ Redo</button>
                      <button type="button" class="fp-action-btn" id="fpGrid">⊞ Grid: On</button>
                      <button type="button" class="fp-action-btn danger" id="fpClear">🗑 Clear All</button>
                      <button type="button" class="fp-action-btn" id="fpZoomIn">＋</button>
                      <button type="button" class="fp-action-btn" id="fpZoomOut">－</button>
                      <button type="button" class="fp-action-btn" id="fpZoomReset">⊙ 100%</button>
                    </div>

                    <div id="fpStageContainer" style="overflow:hidden; background:#fff; position:relative;">
                      <canvas id="fpGridCanvas" style="position:absolute;top:0;left:0;pointer-events:none;"></canvas>
                      <div id="fpStageDiv"></div>
                    </div>

                    <!-- Legend -->
                    <div class="fp-legend">
                      <span style="font-size:.72rem;color:#6c757d;margin-right:4px;font-weight:600;">Legend:</span>
                      <span class="fp-legend-chip"><span class="fp-legend-swatch" style="background:#dbeafe;border:1px solid #93c5fd;"></span>Room / Area</span>
                      <span class="fp-legend-chip"><span class="fp-legend-swatch" style="background:#1e2a3a;"></span>Stage</span>
                      <span class="fp-legend-chip"><span class="fp-legend-swatch" style="background:#dcfce7;border:1px solid #86efac;border-radius:50%;"></span>Table</span>
                      <span class="fp-legend-chip"><span class="fp-legend-swatch" style="background:#fef9c3;border:1px solid #fde047;"></span>Booth</span>
                      <span class="fp-legend-chip"><span class="fp-legend-swatch" style="background:#fce7f3;border:1px solid #f9a8d4;"></span>Exit / Door</span>
                    </div>

                    <div class="fp-statusbar" id="fpStatus">Select a tool to start drawing your floor plan.</div>
                  </div>

                  <!-- Right props panel -->
                  <div class="fp-props">
                    <!-- FLOORS Section -->
                    <div class="fp-floors-section" id="fpFloorsSection">
                      <h4>Floors</h4>
                      <div class="fp-floors-nav" id="fpFloorTabs">
                        <!-- Populated dynamically: Ground Floor, 2nd Floor, 3rd Floor -->
                      </div>
                      <div class="fp-floors-actions">
                        <button type="button" class="fp-add-floor-btn" id="fpAddFloorBtn" onclick="if(window.fpAddFloor)window.fpAddFloor();" title="Add another floor (max 3 floors)">
                          ＋ Add 2nd Floor
                        </button>
                        <span class="fp-floor-max-badge" id="fpFloorMaxBadge" style="display:none;">
                          Max 3 Floors
                        </span>
                      </div>
                    </div>

                    <div class="fp-prop-divider"></div>

                    <h4>Properties</h4>
                    <div class="fp-prop-row" id="propLabelRow">
                      <label>Label</label>
                      <input type="text" id="propLabel" placeholder="e.g. Stage">
                    </div>
                    <div class="fp-prop-row" id="propWRow">
                      <label>Width (px)</label>
                      <input type="number" id="propW" min="10" step="5">
                    </div>
                    <div class="fp-prop-row" id="propHRow">
                      <label>Height (px)</label>
                      <input type="number" id="propH" min="10" step="5">
                    </div>
                    <div class="fp-prop-row" id="propRotRow">
                      <label>Rotation (°)</label>
                      <input type="number" id="propRot" min="-180" max="180" step="5">
                    </div>
                    <div class="fp-prop-row" id="propOpacRow">
                      <label>Opacity</label>
                      <input type="number" id="propOpac" min="0.1" max="1" step="0.1" value="1">
                    </div>
                    <p class="fp-snap-hint">Tip: Hold <strong>Shift</strong> to snap shapes to the grid. Double-click a shape to rename it.</p>
                  </div>
                </div>
              </div>
