/**
 * Minimalist Charting für LWW Reporting (v2.0)
 * Nutzt HTML5 Canvas ohne externe Libraries für maximale Kompatibilität.
 */
function lwwInitCharts(salesData, catData, platformData) {
    // Render Sales Chart (Bar)
    if (salesData && salesData.values.length > 0) {
        drawBarChart('lwwSalesChart', salesData.labels, salesData.values, 'Umsatz (€)', '#3b82f6');
    }
    
    // Render Category Chart (Pie)
    if (catData && catData.values.length > 0) {
        drawPieChart('lwwCatChart', catData.labels, catData.values, ['#f59e0b', '#ef4444', '#22c55e', '#3b82f6', '#64748b']);
    }

    // Render Platform Chart (Pie/Doughnut) - NEU
    if (platformData && platformData.values.length > 0) {
        drawPieChart('lwwPlatformChart', platformData.labels, platformData.values, ['#00a32a', '#e53238', '#96588a', '#2271b1']); // BL Green, BO Red, WC Purple, Generic Blue
    }
}

function drawBarChart(canvasId, labels, data, label, color) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    
    // Resize for high DPI logic could be added here, currently sticking to offset
    canvas.width = canvas.parentElement.offsetWidth;
    canvas.height = canvas.parentElement.offsetHeight;
    
    const padding = 40;
    const chartWidth = canvas.width - (padding * 2);
    const chartHeight = canvas.height - (padding * 2);
    
    // Normalize data
    const maxVal = Math.max(...data, 10) * 1.1; // Ensure maxVal is never 0 to avoid Infinity
    const barWidth = (chartWidth / Math.max(data.length, 1)) * 0.6;
    const gap = (chartWidth / Math.max(data.length, 1)) * 0.4;

    // Draw Axis
    ctx.beginPath();
    ctx.moveTo(padding, padding);
    ctx.lineTo(padding, canvas.height - padding);
    ctx.lineTo(canvas.width - padding, canvas.height - padding);
    ctx.strokeStyle = '#ccc';
    ctx.stroke();

    // Draw Bars
    data.forEach((val, i) => {
        const barHeight = (val / maxVal) * chartHeight;
        const x = padding + (i * (barWidth + gap)) + (gap/2);
        const y = canvas.height - padding - barHeight;

        ctx.fillStyle = color;
        ctx.fillRect(x, y, barWidth, barHeight);

        // Label
        ctx.fillStyle = '#333';
        ctx.font = '10px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(labels[i], x + barWidth/2, canvas.height - padding + 15);
        
        // Value
        ctx.fillText(val.toFixed(2), x + barWidth/2, y - 5);
    });
}

function drawPieChart(canvasId, labels, data, colors) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    
    canvas.width = canvas.parentElement.offsetWidth;
    canvas.height = canvas.parentElement.offsetHeight;

    const total = data.reduce((a, b) => a + b, 0);
    if (total === 0) { // Empty state
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Keine Daten', canvas.width/2, canvas.height/2);
        return;
    }

    let startAngle = 0;
    const centerX = canvas.width / 2;
    const centerY = canvas.height / 2;
    const radius = Math.min(centerX, centerY) - 20;

    data.forEach((val, i) => {
        const sliceAngle = (val / total) * 2 * Math.PI;
        
        ctx.beginPath();
        ctx.moveTo(centerX, centerY);
        ctx.arc(centerX, centerY, radius, startAngle, startAngle + sliceAngle);
        ctx.closePath();
        
        ctx.fillStyle = colors[i % colors.length];
        ctx.fill();
        
        startAngle += sliceAngle;
    });

    // Legend (Simple overlay)
    let legY = 20;
    labels.forEach((lab, i) => {
        ctx.fillStyle = 'rgba(255,255,255,0.8)';
        ctx.fillRect(5, legY - 10, 150, 16);
        
        ctx.fillStyle = colors[i % colors.length];
        ctx.fillRect(10, legY, 10, 10);
        
        ctx.fillStyle = '#000';
        ctx.textAlign = 'left';
        ctx.font = '10px sans-serif';
        const percentage = ((data[i] / total) * 100).toFixed(1) + '%';
        ctx.fillText(lab + ' (' + percentage + ')', 25, legY + 8);
        legY += 20;
    });
}
