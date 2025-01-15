/*
Template Name: Weline - Responsive Bootstrap 4 Admin Dashboard
Author: 秋枫雁飞(aiweline)
Website: https://秋枫雁飞(aiweline).in/
File: Dashboard chart Int js
*/

// Radialchart 1

var radialoptions = {
    series: [72],
    chart: {
        type: 'radialBar',
        width: 72,
        height: 72,
        sparkline: {
            enabled: true
        }
    },
    dataLabels: {
        enabled: false
    },
    colors: ['#0ab39c'],
    stroke: {
        lineCap: 'round'
    },
    plotOptions: {
        radialBar: {
            hollow: {
                margin: 0,
                size: '70%'
            },
            track: {
                margin: 0,
            },

            dataLabels: {
                name: {
                    show: false
                },
                value: {
                    offsetY: 5,
                    show: true
                }
            }
        }
    }
};

var radialchart = new ApexCharts(document.querySelector("#radialchart-1"), radialoptions);
radialchart.render();


// Radialchart 2

var radialoptions = {
    series: [45],
    chart: {
        type: 'radialBar',
        width: 72,
        height: 72,
        sparkline: {
            enabled: true
        }
    },
    dataLabels: {
        enabled: false
    },
    colors: ['#0ab39c'],
    stroke: {
        lineCap: 'round'
    },
    plotOptions: {
        radialBar: {
            hollow: {
                margin: 0,
                size: '70%'
            },
            track: {
                margin: 0,
            },

            dataLabels: {
                name: {
                    show: false
                },
                value: {
                    offsetY: 5,
                    show: true
                }
            }
        }
    }
};

var radialchart = new ApexCharts(document.querySelector("#radialchart-2"), radialoptions);
radialchart.render();


// Radialchart 3

var radialoptions = {
    series: [54],
    chart: {
        type: 'radialBar',
        width: 72,
        height: 72,
        sparkline: {
            enabled: true
        }
    },
    dataLabels: {
        enabled: false
    },
    colors: ['#0ab39c'],
    stroke: {
        lineCap: 'round'
    },
    plotOptions: {
        radialBar: {
            hollow: {
                margin: 0,
                size: '70%'
            },
            track: {
                margin: 0,
            },

            dataLabels: {
                name: {
                    show: false
                },
                value: {
                    offsetY: 5,
                    show: true
                }
            }
        }
    }
};

var radialchart = new ApexCharts(document.querySelector("#radialchart-3"), radialoptions);
radialchart.render();


// Mixed Chart

var options = {
    series: [{
        name: 'Expenses',
        type: 'column',
        data: [23, 11, 22, 27, 13, 22, 37, 21, 44, 22, 30, 18]
    }, {
        name: 'Maintenance',
        type: 'area',
        data: [44, 55, 41, 42, 22, 43, 21, 41, 56, 27, 43, 27]
    }, {
        name: 'Profit',
        type: 'line',
        data: [30, 25, 36, 30, 45, 35, 64, 52, 59, 36, 39, 51]
    }],
    chart: {
        height: 350,
        type: 'line',
        stacked: false,
        toolbar: {
            show: false
        },
    },
    stroke: {
        width: [0, 1, 1],
        dashArray: [0, 0, 5],
        curve: 'smooth'
    },
    plotOptions: {
        bar: {
            columnWidth: '18%'
        }
    },

    legend: {
        show: false,
    },

    fill: {
        opacity: [0.85, 0.25, 1],
        gradient: {
            inverseColors: false,
            shade: 'light',
            type: "vertical",
            opacityFrom: 0.85,
            opacityTo: 0.55,
            stops: [0, 100, 100, 100]
        }
    },
    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    markers: {
        size: 0
    },
    colors: ['#0bb197', '#eff2f7', '#ff3d60'],

};

var chart = new ApexCharts(document.querySelector("#mixed-chart"), options);
chart.render();


// Social Source

var options = {
    chart: {
        height: 350,
        type: 'radialBar',
    },
    plotOptions: {
        radialBar: {
            hollow: {
                margin: 10,
                size: '45%'
            },
            track: {
                show: true,
                strokeWidth: '70%',
                margin: 12, 
               
            },
            dataLabels: {
                name: {
                    fontSize: '27px',
                },
                value: {
                    fontSize: '20px',
                },
                total: {
                    show: true,
                    label: 'Total',
                    formatter: function (w) {
                        // By default this function returns the average of all series. The below is just an example to show the use of custom formatter function
                        return 341
                    }
                }
            }
        }
    },
    series: [44, 55, 67],
    labels: ['Facebook', 'Twitter', 'Instagram'],
    colors: ['#099680', '#4aa3ff', '#5664d2'],

}

var chart = new ApexCharts(document.querySelector("#radialBar-chart"), options);
chart.render();


// usa map

$('#usa').vectorMap({
    map: 'usa_en',
    enableZoom: true,
    showTooltip: true,
    selectedColor: null,
    hoverColor: '#d3d8e2',
    backgroundColor: 'transparent',
    color: '#e8ecf4',
    borderColor: '#424242',
    colors: {
        ca: '#d3d8e2',
        tx: '#d3d8e2',
        mt: '#d3d8e2',
        ny: '#d3d8e2'
    },
    onRegionClick: function (event, code, region) {
        event.preventDefault();
    }

});