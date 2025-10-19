(function (wp) {
  const { registerBlockType } = wp.blocks;
  const { InspectorControls } = wp.blockEditor || wp.editor;
  const { PanelBody, CheckboxControl, SelectControl, __experimentalNumberControl: NumberControl } = wp.components;
  const ServerSideRender = wp.serverSideRender;
  const el = wp.element.createElement;

  const METRICS = [
    { key: "T", label: "Temperature (T)" },
    { key: "TMX", label: "Temp Max (TMX)" },
    { key: "TMN", label: "Temp Min (TMN)" },
    { key: "H", label: "Humidity (H)" },
    { key: "P", label: "Pressure (P)" },
    { key: "W", label: "Wind (W)" },
    { key: "G", label: "Wind Gust (G)" },
    { key: "R", label: "Rain (R)" },
    { key: "RR", label: "Rain Rate (RR)" },
    { key: "S", label: "Wind Direction (S)" },
    { key: "UV", label: "UV Index (UV)" },
    { key: "TIN", label: "Indoor Temp (TIN)" },
    { key: "HIN", label: "Indoor Humidity (HIN)" },
  ];

  function toggleInArray(arr, value) {
    const set = new Set(arr || []);
    if (set.has(value)) set.delete(value);
    else set.add(value);
    return Array.from(set);
  }

  registerBlockType("meteotemplate/meteodata-card", {
    title: "Meteodata Card (v1.8.0)",
    icon: "cloud",
    category: "widgets",
    attributes: {
      fields: { type: "array", default: ["T", "H", "P"] },
      style: { type: "string", default: "table" },
      decimals: { type: "number", default: 1 },
      t_unit: { type: "string", default: "" },
      p_unit: { type: "string", default: "" },
      w_unit: { type: "string", default: "" },
      r_unit: { type: "string", default: "" },
      dir_format: { type: "string", default: "degrees" },
    },

    edit: function (props) {
      const a = props.attributes;
      return el(
        wp.element.Fragment,
        {}, 
        el(
          InspectorControls,
          {}, 
          el(
            PanelBody,
            { title: "Measurements to display" },
            el(
              "div",
              { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: "6px" } },
              METRICS.map((m) =>
                el(CheckboxControl, {
                  key: m.key,
                  label: m.label,
                  checked: (a.fields || []).includes(m.key),
                  onChange: () => props.setAttributes({ fields: toggleInArray(a.fields, m.key) }),
                })
              )
            )
          ),
          el(
            PanelBody,
            { title: "Display" },
            el(SelectControl, {
              label: "Style",
              value: a.style,
              options: [
                { label: "Table", value: "table" },
                { label: "List", value: "list" },
                { label: "Inline", value: "inline" },
              ],
              onChange: (v) => props.setAttributes({ style: v }),
            }),
            el(NumberControl, {
              label: "Decimals",
              value: a.decimals,
              onChange: (v) => props.setAttributes({ decimals: parseInt(v || 0, 10) || 0 }),
            }),
            el(SelectControl, {
              label: "Wind direction format",
              value: a.dir_format || "degrees",
              options: [
                { label: "Degrees (°)", value: "degrees" },
                { label: "Compass (e.g., NNE)", value: "compass" },
              ],
              onChange: (v) => props.setAttributes({ dir_format: v }),
            })
          ),
          el(
            PanelBody,
            { title: "Target Units (optional)" },
            el(SelectControl, {
              label: "Temperature", value: a.t_unit,
              options: [
                { label: "Use Source", value: "" },
                { label: "°C", value: "C" },
                { label: "°F", value: "F" },
              ],
              onChange: (v) => props.setAttributes({ t_unit: v }),
            }),
            el(SelectControl, {
              label: "Pressure", value: a.p_unit,
              options: [
                { label: "Use Source", value: "" },
                { label: "hPa", value: "hPa" },
                { label: "mb", value: "mb" },
                { label: "inHg", value: "inHg" },
                { label: "kPa", value: "kPa" },
              ],
              onChange: (v) => props.setAttributes({ p_unit: v }),
            }),
            el(SelectControl, {
              label: "Wind speed", value: a.w_unit,
              options: [
                { label: "Use Source", value: "" },
                { label: "m/s", value: "mps" },
                { label: "km/h", value: "kmh" },
                { label: "mph", value: "mph" },
                { label: "knots", value: "kn" },
              ],
              onChange: (v) => props.setAttributes({ w_unit: v }),
            }),
            el(SelectControl, {
              label: "Rainfall", value: a.r_unit,
              options: [
                { label: "Use Source", value: "" },
                { label: "mm", value: "mm" },
                { label: "inches", value: "in" },
              ],
              onChange: (v) => props.setAttributes({ r_unit: v }),
            })
          )
        ),
        el(
          "div",
          { className: "meteodata-card-ssr" },
          el(ServerSideRender, {
            block: "meteotemplate/meteodata-card",
            attributes: a,
          })
        )
      );
    },

    save: function () { return null; }
  });
})(window.wp);
