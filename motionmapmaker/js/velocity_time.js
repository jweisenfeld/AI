function draw_velocity_time(data, object) {
  // Calculate velocity from position data
  let velocity_data = [];
  for (let i = 0; i < data.length - 1; i++) {
    let dt = data[i + 1].time - data[i].time;
    let dp = data[i + 1].position - data[i].position;
    let velocity = dt !== 0 ? dp / dt : 0;
    velocity_data.push({
      time: data[i].time,
      velocity: velocity
    });
  }

  // set the dimensions and margins of the graph
  let margin = {top: 40, right: 40, bottom: 60, left: 60},
      width = getWidth()*0.6 - margin.left - margin.right,
      height = window.innerHeight*0.25  - margin.top - margin.bottom;

  // set the ranges
  let x = d3.scaleLinear().range([0, width]);
  let y = d3.scaleLinear().range([height, 0]);

  // define the line
  let valueline = d3.line()
      .x(function(d) { return x(d.time); })
      .y(function(d) { return y(d.velocity); });

  // append the svg object to the body of the page
  let velocity_time = d3.select("#velocity_time").append("svg")
      .attr("width", width + margin.left + margin.right)
      .attr("height", height + margin.top + margin.bottom)
    .append("g")
      .attr("transform",
            "translate(" + margin.left + "," + margin.top + ")");

  // Scale the range of the data
  x.domain([0, 15]);
  let yMin = d3.min(velocity_data, function(d) { return d.velocity; });
  let yMax = d3.max(velocity_data, function(d) { return d.velocity; });
  let yRange = Math.abs(yMax - yMin);
  let yPadding = yRange > 0 ? yRange * 0.1 : 1; // Add 10% padding or minimum of 1
  y.domain([yMin - yPadding, yMax + yPadding]);

  // Add the valueline path.
  velocity_time.append("path")
      .data([velocity_data])
      .attr("d", valueline)
      .style("fill", "none")
      .style("stroke", "blue")
      .style("stroke-width", "2px");

  // Add gridlines
  velocity_time.append("g")
      .attr("class", "grid")
      .attr("transform", "translate(0," + height + ")")
      .call(d3.axisBottom(x)
          .tickValues(d3.range(0, 16, 1))
          .tickSize(-height)
          .tickFormat(""))
      .style("stroke-dasharray", "3,3")
      .style("stroke-opacity", "0.3");

  velocity_time.append("g")
      .attr("class", "grid")
      .call(d3.axisLeft(y)
          .tickSize(-width)
          .tickFormat(""))
      .style("stroke-dasharray", "3,3")
      .style("stroke-opacity", "0.3");

  // Add the X Axis
  velocity_time.append("g")
      .style("font", "18px sans-serif")
      .attr("transform", "translate(0," + height + ")")
      .call(d3.axisBottom(x).tickValues(d3.range(0, 16, 1)));

  // text label for the x axis
  velocity_time.append("text")
      .attr("transform","translate(" + (width/2) + " ," + (height + margin.top + 5) + ")")
      .style("text-anchor", "middle")
      .style("font", "20px sans-serif")
      .text("Time");

  // Add the Y Axis
  velocity_time.append("g")
      .style("font", "18px sans-serif")
      .call(d3.axisLeft(y));

  // text label for the y axis
  velocity_time.append("text")
      .attr("transform", "rotate(-90)")
      .attr("y", 0 - margin.left - 5)
      .attr("x",0 - (height / 2))
      .attr("dy", "1em")
      .style("text-anchor", "middle")
      .style("font", "20px sans-serif")
      .text("Velocity");

  // Adding the Title
  velocity_time.append("text")
        .attr("x", (width / 2))
        .attr("y", 0 - (margin.top / 2))
        .attr("text-anchor", "middle")
        .style("font-size", "24px")
        .style("text-decoration", "bold")
        .text("Velocity vs. Time Graph");
}
