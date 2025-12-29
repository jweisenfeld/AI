function draw_position_time(data, object) {
  // set the dimensions and margins of the graph
  let margin = {top: 40, right: 40, bottom: 60, left: 60},
      width = getWidth()*0.6 - margin.left - margin.right,
      height = getHeight()*0.25  - margin.top - margin.bottom;


  // set the ranges
  let x = d3.scaleLinear().range([0, width]);
  let y = d3.scaleLinear().range([height, 0]);

  // define the line
  let valueline = d3.line()
      .x(function(d) { return x(d.time); })
      .y(function(d) { return y(d.position); });
    
  // append the svg obgect to the body of the page
  // appends a 'group' element to 'svg'
  // moves the 'group' element to the top left margin
  let position_time = d3.select("#position_time").append("svg")
      .attr("width", width + margin.left + margin.right)
      .attr("height", height + margin.top + margin.bottom)
    .append("g")
      .attr("transform",
            "translate(" + margin.left + "," + margin.top + ")");

  //data = data[object]; //Since now it coems from user interface

  //This is where data munging can take place
  // format the data
  //data.forEach(function(d) {
  //    d.time = d.time;
  //    d.position = d.position;
  //});


  // Scale the range of the data
  x.domain([0, 15]);
  let yMin = d3.min(data, function(d) { return d.position; });
  let yMax = d3.max(data, function(d) { return d.position; });
  let yRange = Math.abs(yMax - yMin);
  let yPadding = yRange > 0 ? yRange * 0.1 : 1; // Add 10% padding or minimum of 1
  y.domain([yMin - yPadding, yMax + yPadding]);

  console.log("Position graph - yMin:", yMin, "yMax:", yMax, "domain:", [yMin - yPadding, yMax + yPadding]);
  
  // Add the valueline path.
  position_time.append("path")
      .data([data])
      .attr("d", valueline) //      //.attr("class", "line") for css class attributes
      .style("fill", "none")
      .style("stroke", "black")
      .style("stroke-width", "2px");

  // Add gridlines
  position_time.append("g")
      .attr("class", "grid")
      .attr("transform", "translate(0," + height + ")")
      .call(d3.axisBottom(x)
          .tickValues(d3.range(0, 16, 1))
          .tickSize(-height)
          .tickFormat(""))
      .style("stroke-dasharray", "3,3")
      .style("stroke-opacity", "0.3");

  position_time.append("g")
      .attr("class", "grid")
      .call(d3.axisLeft(y)
          .tickSize(-width)
          .tickFormat(""))
      .style("stroke-dasharray", "3,3")
      .style("stroke-opacity", "0.3");

  // Add the X Axis
  position_time.append("g")
      .style("font", "18px sans-serif")
      .attr("transform", "translate(0," + height + ")")
      .call(d3.axisBottom(x).tickValues(d3.range(0, 16, 1)));

  // text label for the x axis
  position_time.append("text")
      .attr("transform","translate(" + (width/2) + " ," + (height + margin.top + 5) + ")")
      .style("text-anchor", "middle")
      .style("font", "20px sans-serif")
      .text("Time");

  // Add the Y Axis
  position_time.append("g")
      .style("font", "18px sans-serif")
      .call(d3.axisLeft(y));

  // text label for the y axis
  position_time.append("text")
      .attr("transform", "rotate(-90)")
      .attr("y", 0 - margin.left - 5)
      .attr("x",0 - (height / 2))
      .attr("dy", "1em")
      .style("text-anchor", "middle")
      .style("font", "20px sans-serif")
      .text("Position");  

  //Adding the Title
  position_time.append("text")
        .attr("x", (width / 2))             
        .attr("y", 0 - (margin.top / 2))
        .attr("text-anchor", "middle")  
        .style("font-size", "24px") 
        .style("text-decoration", "bold")  
        .text("Position vs. Time Graph");
  }


// Get the data
/* Since I try to get data from other one now.
d3.json("data/data.json", function(error, data) {
  if (error) throw error;
  
  // trigger render
  draw_position_time(data, "object1");

});
*/
