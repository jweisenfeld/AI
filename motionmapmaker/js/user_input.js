let objectData = [
      {time: 0, position: 0},
      {time: 1, position: 1},
      {time: 2, position: 2},
      {time: 3, position: 3},
      {time: 4, position: 4},
      {time: 5, position: 5},
      {time: 6, position: 5},
      {time: 7, position: 5},
      {time: 8, position: 5},
      {time: 9, position: 5},
      {time: 10, position: 5},
      {time: 11, position: 4},
      {time: 12, position: 3},
      {time: 13, position: 2},
      {time: 14, position: 1},
      {time: 15, position: 0},
    ],
    container = document.getElementById('table_input'),
    hot;

 hot = new Handsontable(container, {
    data: objectData,
    colHeaders: true,
    minSpareRows: 0,
    className: "htCenter"
  });
hot.updateSettings({
  colHeaders: ["Time", "Position"]
});

function verify_data(hot){
  data = hot.getData();
  actual_data = [];
  for (i=0;i<data.length;i++){
    if (data[i][0] !== "" && data[i][1] !== "") {
      actual_data.push(data[i]);
    }
  }
  data = actual_data
  old_time_interval = data[1][0] - data[0][0];
  for (i=0;i<data.length-1;i++){
    time_interval = data[i+1][0] - data[i][0];
    if (time_interval != old_time_interval){
      alert("Motion maps require a fixed time interval between points! This will be assumed for the motion map.");
    }
    old_time_interval = time_interval;
  }
  return actual_data;
}

function update_graphs(hot){
  d3.selectAll("svg").remove(); //Delete out the graphs before addint new graphs
  data = verify_data(hot); 
  func_data = [];
  for (i=0;i<data.length;i++){
    func_data.push({
      time: data[i][0],
      position: data[i][1]
      });
    }
  draw_motion_map(func_data,document.getElementById("half-arrows").checked);
  draw_position_time(func_data,"hello");
}

window.onload = update_graphs(hot);

function post_email(){
  document.getElementById('email_form').addEventListener("submit", function(event){
    event.preventDefault();
    
    let email_value = document.getElementById('email').value;
    console.log(email_value);
    let body = JSON.stringify({email:email_value,source:"motionmapmaker.com"});
    //console.log(body);
    //Posting to an API
    //let url = "http://localhost:5000/leads/all"
    let url = "https://7h2t1ukf34.execute-api.us-east-1.amazonaws.com/prod/leads/all"
    let xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('content-type', 'application/x-amz-json-1.0');
    xhr.setRequestHeader('data-type', 'json');
    xhr.onload = function() {
        //console.log(xhr.status);
    };
    xhr.send(JSON.stringify({
                "email": email_value,
                "source": "motionmapmaker.com"
              }));

    document.getElementById("email_form").style.display = "none"; 
    document.getElementById("thanks").style.visibility = "visible"; 
    return false;
  });
  /*

  */
}

// Set-up the export button for position time
d3.select('#savePositionTimeButton').on('click', function(){
  console.log(d3.select('#position_time svg').node().width.baseVal.value);
  var width = d3.select('#position_time svg').node().width.baseVal.value;
  var height = d3.select('#position_time svg').node().height.baseVal.value;
  var svgString = getSVGString(d3.select('#position_time svg').node());
  svgString2Image( svgString, 2*width, 2*height, 'png', save ); // passes Blob and filesize String to the callback

  function save( dataBlob, filesize ){
    saveAs( dataBlob, 'position-time.png' ); // FileSaver.js function
  }
});

// Set-up the export button for position time
d3.select('#saveMotionMapButton').on('click', function(){
  console.log(d3.select('#motion_map svg').node().width.baseVal.value);
  var width = d3.select('#motion_map svg').node().width.baseVal.value;
  var height = d3.select('#motion_map svg').node().height.baseVal.value;
  var svgString = getSVGString(d3.select('#motion_map svg').node());
  svgString2Image( svgString, 2*width, 2*height, 'png', save ); // passes Blob and filesize String to the callback

  function save( dataBlob, filesize ){
    saveAs( dataBlob, 'motion-map.png' ); // FileSaver.js function
  }
});

// Below are the functions that handle actual exporting:
// getSVGString ( svgNode ) and svgString2Image( svgString, width, height, format, callback )
function getSVGString( svgNode ) {
  svgNode.setAttribute('xlink', 'http://www.w3.org/1999/xlink');
  var cssStyleText = getCSSStyles( svgNode );
  appendCSS( cssStyleText, svgNode );

  var serializer = new XMLSerializer();
  var svgString = serializer.serializeToString(svgNode);
  svgString = svgString.replace(/(\w+)?:?xlink=/g, 'xmlns:xlink='); // Fix root xlink without namespace
  svgString = svgString.replace(/NS\d+:href/g, 'xlink:href'); // Safari NS namespace fix

  return svgString;

  function getCSSStyles( parentElement ) {
    var selectorTextArr = [];

    // Add Parent element Id and Classes to the list
    selectorTextArr.push( '#'+parentElement.id );
    for (var c = 0; c < parentElement.classList.length; c++)
        if ( !contains('.'+parentElement.classList[c], selectorTextArr) )
          selectorTextArr.push( '.'+parentElement.classList[c] );

    // Add Children element Ids and Classes to the list
    var nodes = parentElement.getElementsByTagName("*");
    for (var i = 0; i < nodes.length; i++) {
      var id = nodes[i].id;
      if ( !contains('#'+id, selectorTextArr) )
        selectorTextArr.push( '#'+id );

      var classes = nodes[i].classList;
      for (var c = 0; c < classes.length; c++)
        if ( !contains('.'+classes[c], selectorTextArr) )
          selectorTextArr.push( '.'+classes[c] );
    }

    // Extract CSS Rules
    var extractedCSSText = "";
    for (var i = 0; i < document.styleSheets.length; i++) {
      var s = document.styleSheets[i];
      
      try {
          if(!s.cssRules) continue;
      } catch( e ) {
            if(e.name !== 'SecurityError') throw e; // for Firefox
            continue;
          }

      var cssRules = s.cssRules;
      for (var r = 0; r < cssRules.length; r++) {
        if ( contains( cssRules[r].selectorText, selectorTextArr ) )
          extractedCSSText += cssRules[r].cssText;
      }
    }
    

    return extractedCSSText;

    function contains(str,arr) {
      return arr.indexOf( str ) === -1 ? false : true;
    }

  }

  function appendCSS( cssText, element ) {
    var styleElement = document.createElement("style");
    styleElement.setAttribute("type","text/css"); 
    styleElement.innerHTML = cssText;
    var refNode = element.hasChildNodes() ? element.children[0] : null;
    element.insertBefore( styleElement, refNode );
  }
}


function svgString2Image( svgString, width, height, format, callback ) {
  var format = format ? format : 'png';

  var imgsrc = 'data:image/svg+xml;base64,'+ btoa( unescape( encodeURIComponent( svgString ) ) ); // Convert SVG string to data URL

  var canvas = document.createElement("canvas");
  var context = canvas.getContext("2d");

  canvas.width = width;
  canvas.height = height;

  var image = new Image();
  image.onload = function() {
    context.clearRect ( 0, 0, width, height );
    context.drawImage(image, 0, 0, width, height);

    canvas.toBlob( function(blob) {
      var filesize = Math.round( blob.length/1024 ) + ' KB';
      if ( callback ) callback( blob, filesize );
    });

    
  };

  image.src = imgsrc;
}
