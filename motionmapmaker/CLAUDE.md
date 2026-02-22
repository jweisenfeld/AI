# motionmapmaker/ — CLAUDE.md

## Purpose
Interactive web tool for creating kinematics motion maps in physics class.
Students input position/velocity data points and the tool generates motion maps
and position-time, velocity-time, and acceleration-time graphs.

## Key Files
| File | Description |
|------|-------------|
| `index.html` | Main interface |
| `test_graphs.html` | Graph testing interface |
| `js/motion_map.js` | Core motion mapping logic (~14KB) |
| `js/position_time.js` | Position-time graph rendering |
| `js/velocity_time.js` | Velocity-time graph rendering |
| `js/acceleration_time.js` | Acceleration-time graph rendering |
| `js/user_input.js` | Input handling and validation (~9KB) |
| `js/lib/` | D3.js and other library dependencies |
| `css/base.css` | Base styles |
| `css/style.css` | Main styles (~2.5KB) |
| `css/lib/` | CSS library dependencies |
| `images/` | Physics diagram images |
| `version.txt` | Current version number |

## Technology
Built with **D3.js** for smooth, interactive SVG-based graph rendering.
Each graph type has its own JS module for maintainability.

## Deployment
Deployed as a static site at `psd1.net/motionmapmaker`.
No backend required — entirely client-side JavaScript.
