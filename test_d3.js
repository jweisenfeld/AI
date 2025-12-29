// Simulate the data
const data = [
  {time: 0, position: 100}, {time: 1, position: 86}, {time: 2, position: 74},
  {time: 3, position: 64}, {time: 4, position: 56}, {time: 5, position: 50},
  {time: 6, position: 46}, {time: 7, position: 44}, {time: 8, position: 44},
  {time: 9, position: 46}, {time: 10, position: 50}, {time: 11, position: 56},
  {time: 12, position: 64}, {time: 13, position: 74}, {time: 14, position: 86},
  {time: 15, position: 100}
];

// Simulate the domain calculation
let yMin = Math.min(...data.map(d => d.position));
let yMax = Math.max(...data.map(d => d.position));
let yRange = Math.abs(yMax - yMin);
let yPadding = yRange > 0 ? yRange * 0.1 : 1;

console.log("Data range:", yMin, "to", yMax);
console.log("yRange:", yRange);
console.log("yPadding:", yPadding);
console.log("Domain should be: [", yMin - yPadding, ",", yMax + yPadding, "]");
console.log("Expected: ~[38.4, 105.6]");
