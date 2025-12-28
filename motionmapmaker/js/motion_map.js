function get_motion_map_data_from_position_time_data(data) {
  //Getting the basic data into arrays - May not be necessary when I switch to HandsOnTable
  let time = [];
  let position = [];
  for (i=0;i<data.length;i++) {
    time.push(data[i].time);
    position.push(data[i].position);
  } 
  //Getting the number of levels - I could do away with this and just start with an arbitrary number.
  let level_count = 0;//get_motion_map_level_count(time, position)

  //Creating the motion-map data 
  motion_map_data = [];

  //First point starts on top level
  motion_map_data.push({
        time: time[0],
        position: position[0],
        motion_map_y: level_count,
        next_level_arrow_end: false
    });

  for (i=1;i<time.length-1;i++) {
    //first derivative values 
    pos_diff = [];
    pos_diff.push(Math.sign(position[i] - position[i-1]));
    pos_diff.push(Math.sign(position[i+1] - position[i]));

    //Second derivative values
    dir_change = pos_diff[1] - pos_diff[0];

    //arrow thins
    let additional_arrow_to_beginning_of_next_level;

    //First option for dir_change is zero
    if (dir_change == 0) {
      if ((pos_diff[0] != 0) && (pos_diff[1] != 0)) {
          level_count = level_count; 
          additional_arrow_to_beginning_of_next_level = false;
      }
      else if ( (pos_diff[0] == 0) && (pos_diff[1] == 0) ){
        level_count = level_count - 1; 
        additional_arrow_to_beginning_of_next_level = false;

      }
    }

    //Second option for dir_change is 1 plus of minus
    if (Math.abs(dir_change) == 1){
      // If the previous step is not a stop it doesn't need a new level
      if (i < 2) { // Can't determine if we are at the very beginning of the sequence
        level_count = level_count;
        additional_arrow_to_beginning_of_next_level = false;
      }
      else{
        previous_pos_diff = [];
        previous_pos_diff.push(Math.sign(position[i-1] - position[i-2]));
        previous_pos_diff.push(Math.sign(position[i] - position[i-1]));
        if (previous_pos_diff[1] == 0) {
          level_count = level_count - 1;
          additional_arrow_to_beginning_of_next_level = false;
        }
        else{
          level_count = level_count;
          additional_arrow_to_beginning_of_next_level = false;
        }
      }
    }

    //Third option for dir_change is plus or minus
    if (Math.abs(dir_change) == 2) {
      level_count = level_count - 1;
      additional_arrow_to_beginning_of_next_level = true;
    }

    //Appending the dot to the list and indicating if level ends in arrow
    if (additional_arrow_to_beginning_of_next_level){
      motion_map_data[motion_map_data.length - 1].next_level_arrow_end = true;
    }

    //First point might not be motion so this will catch that case
    if ((i==1)&&(position[1]==position[0])&&(motion_map_data[motion_map_data.length - 1].motion_map_y == level_count)){
      level_count = level_count - 1;
    }
    motion_map_data.push({
                time: time[i],
                position: position[i],
                motion_map_y: level_count,
                next_level_arrow_end: false
            });
  }

  //Last Point Tags along on same level
  if (position[position.length - 1] == position[position.length -2]) {
    level_count = level_count - 1;
  }
  motion_map_data.push({
                time: time[time.length - 1],
                position: position[position.length -1],
                motion_map_y: level_count,
                next_level_arrow_end: false
            });
  return motion_map_data; 
}

function getWidth() {
  return Math.max(
    document.body.scrollWidth,
    document.documentElement.scrollWidth,
    document.body.offsetWidth,
    document.documentElement.offsetWidth,
    document.documentElement.clientWidth
  );
}

function getHeight() {
  return Math.max(
    document.body.scrollHeight,
    document.documentElement.scrollHeight,
    document.body.offsetHeight,
    document.documentElement.offsetHeight,
    document.documentElement.clientHeight
  );
}

function get_arrow_data_from_motion_map_data(motion_map_data,arrow_length=false){ //arrow_length 1 is half 0 is half length
  let arrow_data = [];
  let current_level = motion_map_data[0].motion_map_y;
  for (i=0;i<motion_map_data.length - 1;i++){
    let level;
    let start;
    let end;
    let direction;
    let draw_arrow;

    //If a point and the next point are on the same level connect them with an arrow. 
    if (motion_map_data[i].motion_map_y == motion_map_data[i+1].motion_map_y) {
      level = motion_map_data[i].motion_map_y;
      start = motion_map_data[i].position;
      if (arrow_length){
        end = motion_map_data[i+1].position - ((motion_map_data[i+1].position - start)/2);
      }
      else {
        end = motion_map_data[i+1].position;
      }
      direction = Math.sign(motion_map_data[i+1].position - motion_map_data[i].position);
      draw_arrow = true;
    }

    //If the points are on a different level but the arrow is supposed to go to the beginning of next level for dir change
    else if ((motion_map_data[i].motion_map_y == motion_map_data[i+1].motion_map_y + 1) && (motion_map_data[i].next_level_arrow_end == true)) {
      level = motion_map_data[i].motion_map_y;
      start = motion_map_data[i].position;
      if (arrow_length){
        end = motion_map_data[i+1].position - ((motion_map_data[i+1].position - start)/2);
      }
      else{
        end = motion_map_data[i+1].position;
      }
      direction = Math.sign(motion_map_data[i+1].position - motion_map_data[i].position);
      draw_arrow = true;
    }

    //If it is a new level that ends on a dot and doesn't need any more arrows on this level
    else {
      draw_arrow = false

    }
    if (draw_arrow){
      arrow_data.push({
                level: level,
                start: start,
                end: end,
                direction: direction
            });
    }
  }
  return arrow_data
}

function arrow(level, start, end, radius,motion_map,direction,arrow_length=false) {
  let adjustment;
  if (direction > 0){
      adjustment = -5*radius
  }
  if (direction < 0){
      adjustment = 5*radius // Radius thing
  }
  if (arrow_length){
    adjustment = 0;
  }

  motion_map.append("line")
            .attr("x1", start)
            .attr("y1", level)
            .attr("x2", end + adjustment) //Controls the arrow direction
            .attr("y2", level)
            .attr("stroke-width", 1)
            .attr("stroke", "black")
            .attr("marker-end", "url(#triangle)"); 
}

function draw_motion_map(data, arrow_length=false) {
  let margin = {top: 40, right: 40, bottom: 60, left: 60},
      width = getWidth()*0.6 - margin.left - margin.right,
      height = getHeight()*0.15 - margin.top - margin.bottom;
  //Create Xscale
  let x = d3.scaleLinear().range([0, width]);
  let y = d3.scaleLinear().range([height, 0]);

  //Create SVG element
  // append the svg obgect to the body of the page
  // appends a 'group' element to 'svg'
  // moves the 'group' element to the top left margin

  let motion_map = d3.select("#motion_map").append("svg")
      .attr("width", width + margin.left + margin.right)
      .attr("height", height + margin.top + margin.bottom)
    .append("g")
      .attr("transform",
            "translate(" + margin.left + "," + margin.top + ")");
  //arrow style
  motion_map.append("svg:defs").append("svg:marker")
      .attr("id", "triangle")
      .attr("refX", 6)
      .attr("refY", 6)
      .attr("markerWidth", 30)
      .attr("markerHeight", 30)
      .attr("markerUnits","userSpaceOnUse")
      .attr("orient", "auto")
      .append("path")
      .attr("d", "M 0 0 12 6 0 12 3 6")
      .style("fill", "black");

  //data = get_motion_map_data_from_position_time_data(data[object]); //From File
  data = get_motion_map_data_from_position_time_data(data);

  // Scale the range of the data
  x.domain([d3.min(data, function(d) { return Math.min(d.position); }) , d3.max(data, function(d) { return Math.max(d.position); })]);
  y.domain([d3.min(data, function(d) { return Math.min(d.motion_map_y) - 1; }) , d3.max(data, function(d) { return Math.max(d.motion_map_y); })]);

  //Create circles
  motion_map.selectAll("circle")
     .data(data)
     .enter()
     .append("circle")
     .attr("cx", function(d) {
        return x(d.position);
     })
     .attr("cy", function(d) {
        return y(d.motion_map_y);
     })
     .attr("r", function(d) {
        return 5; //fixed radius...? 
     })

  // Add the X Axis
  motion_map.append("g")
    .style("font", "18px sans-serif")
    .attr("transform", "translate(0," + height + ")")
    .call(d3.axisBottom(x));

  // text label for the x axis
  motion_map.append("text")             
      .attr("transform", "translate(" + (width/2) + " ," + (height + margin.top + 5) + ")")
      .style("text-anchor", "middle")
      .style("font", "20px sans-serif")
      .text("Position");

  // Add the Y Axis --- Keeping it hidden since you don't really draw it on a motion map
  //svg.append("g")
  //  .call(d3.axisLeft(y));


  //Adding The Arrows - This is not d3 so I need a function for adding arrows to the svg - Need to determine segments
  let arrow_data = get_arrow_data_from_motion_map_data(data,arrow_length);
  for (n = 0; n < arrow_data.length; n++){
    arrow(y(arrow_data[n].level),x(arrow_data[n].start), x(arrow_data[n].end), 5, motion_map, arrow_data[n].direction,arrow_length)
  }

  //Adding the title
  motion_map.append("text")
          .attr("x", (width / 2))             
          .attr("y", 0 - (margin.top / 2))
          .attr("text-anchor", "middle")  
          .style("font-size", "24px") 
          .style("text-decoration", "bold")  
          .text("Motion Map");
}

// Get the data
/* Taken out since user input now
d3.json("data/data.json", function(error, data) {
  if (error) throw error;
  
  // trigger render
  draw_motion_map(data, "object1");
});
*/
